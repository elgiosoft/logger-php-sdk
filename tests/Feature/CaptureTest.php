<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Tests\Feature;

use Elgiosoft\Logger\Support\BodyCapture;
use Elgiosoft\Logger\Support\Redactor;
use Elgiosoft\Logger\Tests\TestCase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

final class CaptureTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        Route::post('/webhooks/provider', fn () => response()->json(['received' => true, 'token' => 'reply-secret']));
        Route::post('/api/orders', fn () => response()->json(['id' => 1]));
        Route::post('/pay', function () {
            Http::withToken('provider-key')->post('https://api.provider.test/v1/collect', ['amount' => 5000, 'msisdn' => '237690000000', 'pin' => '1234']);

            return 'ok';
        });
    }

    protected function captureEverything($app): void
    {
        $app['config']->set('elgiosoft-logger.capture.request_paths', 'webhooks/*, callbacks/*');
        $app['config']->set('elgiosoft-logger.capture.http_client', 'true');
    }

    protected function captureUnsampled($app): void
    {
        $this->captureEverything($app);
        $app['config']->set('elgiosoft-logger.tracing.sample_rate', 0.0);
    }

    protected function captureWithTracingOff($app): void
    {
        $this->captureEverything($app);
        $app['config']->set('elgiosoft-logger.tracing.enabled', false);
    }

    #[\Orchestra\Testbench\Attributes\DefineEnvironment('captureEverything')]
    public function test_webhook_bodies_headers_and_responses_are_kept_with_secrets_masked(): void
    {
        $this->fakeCollector();

        $this->postJson('/webhooks/provider?attempt=2', ['status' => 'SUCCESSFUL', 'amount' => 5000, 'pin' => '1234'], [
            'Authorization' => 'Bearer abc',
            'Cookie' => 'session=1',
            'X-Signature' => 'sha256=deadbeef',
        ])->assertOk();
        $this->app->terminate();

        $span = collect($this->sentSpans())->firstWhere('op', 'http.server');
        $attributes = $span['attributes'];

        $this->assertSame(['status' => 'SUCCESSFUL', 'amount' => 5000, 'pin' => Redactor::FILTERED], $attributes['http.request.body']);
        $this->assertSame(['attempt' => '2'], $attributes['http.request.query']);
        $this->assertSame('sha256=deadbeef', $attributes['http.request.headers']['x-signature']);
        $this->assertArrayNotHasKey('authorization', $attributes['http.request.headers']);
        $this->assertArrayNotHasKey('cookie', $attributes['http.request.headers']);
        $this->assertSame(['received' => true, 'token' => Redactor::FILTERED], $attributes['http.response.body']);
    }

    #[\Orchestra\Testbench\Attributes\DefineEnvironment('captureEverything')]
    public function test_other_paths_keep_no_bodies(): void
    {
        $this->fakeCollector();

        $this->postJson('/api/orders', ['amount' => 1])->assertOk();
        $this->app->terminate();

        $attributes = collect($this->sentSpans())->firstWhere('op', 'http.server')['attributes'];
        $this->assertArrayNotHasKey('http.request.body', $attributes);
        $this->assertArrayNotHasKey('http.response.body', $attributes);
    }

    public function test_nothing_is_captured_by_default(): void
    {
        $this->fakeCollector();

        $this->postJson('/webhooks/provider', ['amount' => 1])->assertOk();
        $this->app->terminate();

        $attributes = collect($this->sentSpans())->firstWhere('op', 'http.server')['attributes'];
        $this->assertArrayNotHasKey('http.request.body', $attributes);
        $this->assertArrayNotHasKey('http.request.headers', $attributes);
    }

    #[\Orchestra\Testbench\Attributes\DefineEnvironment('captureUnsampled')]
    public function test_outgoing_calls_keep_bodies_even_when_the_trace_is_not_sampled(): void
    {
        if (! method_exists(Factory::class, 'globalMiddleware')) {
            $this->markTestSkipped('The HTTP client has no global middleware before Laravel 10.');
        }

        $this->fakeCollector();

        $this->post('/pay')->assertOk();
        $this->app->terminate();

        $spans = collect($this->sentSpans());
        $call = $spans->firstWhere('op', 'http.client');

        $this->assertNotNull($call, 'the provider call is kept although the trace was not sampled');
        $this->assertFalse($call['attributes']['sampled']);
        $this->assertSame(['amount' => 5000, 'msisdn' => '237690000000', 'pin' => Redactor::FILTERED], $call['attributes']['http.request.body']);
        $this->assertSame(['ok' => true], $call['attributes']['http.response.body']);
        $this->assertArrayNotHasKey('authorization', $call['attributes']['http.request.headers']);
        $this->assertSame(200, $call['status_code']);

        // The app still read the response normally (the body was rewound after capture).
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.provider.test/v1/collect');
        $this->assertEqualsCanonicalizing(['http.server', 'http.client'], $spans->pluck('op')->all(), 'only the request summary and the provider call, no other spans');
    }

    #[\Orchestra\Testbench\Attributes\DefineEnvironment('captureWithTracingOff')]
    public function test_nothing_is_kept_when_tracing_is_off(): void
    {
        $this->fakeCollector();

        $this->post('/pay')->assertOk();
        $this->postJson('/webhooks/provider', ['amount' => 1])->assertOk();
        $this->app->terminate();

        $this->assertSame([], $this->sentSpans());
    }

    public function test_text_bodies_are_masked_cut_and_binary_is_described(): void
    {
        $capture = new BodyCapture(new Redactor(['pin', 'password']), 64);

        $this->assertSame('msisdn=237690000000&pin='.Redactor::FILTERED, $capture->fromString('msisdn=237690000000&pin=1234', 'text/plain'));
        $this->assertSame('<req><amount>500</amount><pin>'.Redactor::FILTERED.'</pin></req>', $capture->fromString('<req><amount>500</amount><pin>4321</pin></req>', 'text/xml'));
        $this->assertSame(['msisdn' => '237', 'pin' => '1234'], $capture->fromString('msisdn=237&pin=1234', 'application/x-www-form-urlencoded'), 'arrays are masked later by the client');
        $this->assertSame('[binary body, 4 bytes]', $capture->fromString("\x89PNG", 'image/png'));
        $this->assertNull($capture->fromString('', 'application/json'));

        $long = $capture->fromString(str_repeat('a', 100), 'text/plain');
        $this->assertStringStartsWith(str_repeat('a', 64).'… [cut, 100 bytes in total]', $long);
    }
}
