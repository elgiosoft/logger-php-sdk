<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Tests\Feature;

use Elgiosoft\Logger\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class LogChannelTest extends TestCase
{
    public function test_log_records_are_sent_as_contract_envelopes(): void
    {
        $this->fakeCollector();

        Log::channel('elgiosoft')->error('Maviance cashout failed', [
            'event' => 'payment.failed',
            'transaction_id' => 'txn_123456',
            'provider' => 'maviance',
            'amount' => 25000,
            'customer' => ['phone' => '237670000000', 'pin' => '1234'],
            'password' => 'hunter2',
        ]);

        $this->client()->flush();

        Http::assertSent(fn (Request $request): bool => $request->url() === self::ENDPOINT.'/api/v1/ingest'
            && $request->hasHeader('Authorization', 'Bearer '.self::KEY)
            && $request->method() === 'POST');

        [$envelope] = $this->sentEnvelopes();

        $this->assertSame(['name' => 'elgiosoft/logger-php', 'version' => '1.0.0'], $envelope['sdk']);
        $this->assertSame('testing', $envelope['environment']);
        $this->assertSame('1.2.3', $envelope['release']);
        $this->assertSame('test-host', $envelope['server_name']);
        $this->assertCount(1, $envelope['logs']);

        $log = $envelope['logs'][0];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $log['id']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $log['timestamp']);
        $this->assertSame('error', $log['level']);
        $this->assertSame('Maviance cashout failed', $log['message']);
        $this->assertSame('payment.failed', $log['event']);
        $this->assertSame('txn_123456', $log['transaction_id']);
        $this->assertSame('testing', $log['channel']);
        $this->assertSame('maviance', $log['context']['provider']);
        $this->assertSame(25000, $log['context']['amount']);
        $this->assertSame('[Filtered]', $log['context']['password']);
        $this->assertSame('[Filtered]', $log['context']['customer']['pin']);
        $this->assertSame('237670000000', $log['context']['customer']['phone']);
        $this->assertArrayNotHasKey('event', $log['context']);
        $this->assertArrayNotHasKey('transaction_id', $log['context']);
    }

    public function test_monolog_levels_map_to_psr_level_names(): void
    {
        $this->fakeCollector();

        foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'] as $level) {
            Log::channel('elgiosoft')->log($level, "a {$level} message");
        }

        $this->client()->flush();

        $this->assertSame(
            ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'],
            array_column($this->sentLogs(), 'level'),
        );
    }

    public function test_channel_level_filters_records(): void
    {
        $this->fakeCollector();
        config(['logging.channels.errors_only' => ['driver' => 'elgiosoft', 'level' => 'error']]);

        Log::channel('errors_only')->info('ignored');
        Log::channel('errors_only')->error('kept');
        $this->client()->flush();

        $this->assertSame(['kept'], array_column($this->sentLogs(), 'message'));
    }

    public function test_objects_and_tags_in_context_are_normalized(): void
    {
        $this->fakeCollector();

        Log::channel('elgiosoft')->info('Normalized', [
            'when' => new \DateTimeImmutable('2026-10-08 10:00:00', new \DateTimeZone('UTC')),
            'object' => new \ArrayObject([1, 2]),
            'nan' => NAN,
            'tags' => ['provider' => 'mtn', 'retry' => true],
        ]);
        $this->client()->flush();

        $log = $this->sentLogs()[0];
        $this->assertSame('2026-10-08T10:00:00.000000Z', $log['context']['when']);
        $this->assertSame('NAN', $log['context']['nan']);
        $this->assertSame(['provider' => 'mtn', 'retry' => 'true'], $log['tags']);
        $this->assertArrayNotHasKey('tags', $log['context']);
    }

    public function test_nothing_is_captured_without_a_key(): void
    {
        $this->fakeCollector();
        config(['elgiosoft-logger.key' => null]);
        $this->app->forgetInstance(\Elgiosoft\Logger\Client::class);

        Log::channel('elgiosoft')->error('dropped');
        $this->client()->flush();

        Http::assertNothingSent();
    }
}
