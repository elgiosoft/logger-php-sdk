<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Tests\Feature;

use Elgiosoft\Logger\Client;
use Elgiosoft\Logger\Facades\ElgioLogger;
use Elgiosoft\Logger\Tests\Fixtures\ProviderTimeout;
use Elgiosoft\Logger\Tests\TestCase;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use RuntimeException;

final class ExceptionHandlerTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        Route::get('/explode', function () {
            Log::info('Charging the card');

            throw new RuntimeException('Kaboom');
        });
    }

    /**
     * The app logs to a channel other than "elgiosoft".
     */
    private function withoutTheChannel(): void
    {
        config(['logging.default' => 'fallback']);
    }

    public function test_reported_exceptions_are_captured_without_the_channel_in_the_stack(): void
    {
        $this->withoutTheChannel();
        $this->fakeCollector();

        report(new RuntimeException('Provider unreachable'));
        $this->client()->flush();

        $logs = $this->sentLogs();
        $this->assertCount(1, $logs);
        $this->assertSame('error', $logs[0]['level']);
        $this->assertSame('Provider unreachable', $logs[0]['message']);
        $this->assertSame(RuntimeException::class, $logs[0]['exception']['values'][0]['type']);
        $this->assertArrayNotHasKey('channel', $logs[0]);
    }

    public function test_exceptions_are_sent_once_when_the_channel_is_in_the_stack(): void
    {
        $this->fakeCollector();

        report(new ProviderTimeout('Maviance cashout timed out', 504));
        $this->client()->flush();

        $logs = $this->sentLogs();
        $this->assertCount(1, $logs);
        $this->assertSame('Maviance cashout timed out', $logs[0]['message']);
        $this->assertSame('testing', $logs[0]['channel']);
        $this->assertSame(ProviderTimeout::class, $logs[0]['exception']['values'][0]['type']);
    }

    public function test_unhandled_request_exceptions_are_sent_once_with_breadcrumbs(): void
    {
        $this->withoutTheChannel();
        $this->fakeCollector();

        $this->get('/explode')->assertStatus(500);
        $this->app->terminate();

        $errors = array_values(array_filter($this->sentLogs(), fn (array $log): bool => $log['message'] === 'Kaboom'));
        $this->assertCount(1, $errors);
        $this->assertSame(['Charging the card'], array_column($errors[0]['context']['breadcrumbs'], 'message'));
        $this->assertNotEmpty($errors[0]['trace_id']);
    }

    public function test_dont_report_is_respected(): void
    {
        $this->fakeCollector();
        $handler = $this->app->make(ExceptionHandler::class);

        if (! method_exists($handler, 'dontReport')) {
            $this->markTestSkipped('dontReport() needs Laravel 10+.');
        }

        $handler->dontReport(InvalidArgumentException::class);

        report(new InvalidArgumentException('ignored'));
        $this->client()->flush();

        $this->assertSame([], $this->sentLogs());
    }

    public function test_the_handler_log_level_mapping_is_used(): void
    {
        $this->withoutTheChannel();
        $this->fakeCollector();
        $handler = $this->app->make(ExceptionHandler::class);

        if (! method_exists($handler, 'level')) {
            $this->markTestSkipped('level() needs Laravel 10+.');
        }

        $handler->level(ProviderTimeout::class, 'critical');

        report(new ProviderTimeout('timed out'));
        $this->client()->flush();

        $this->assertSame(['critical'], array_column($this->sentLogs(), 'level'));
    }

    public function test_an_exception_captured_by_hand_is_not_captured_again_when_reported(): void
    {
        $this->fakeCollector();
        $exception = new RuntimeException('handled then rethrown');

        ElgioLogger::captureException($exception, ['order_id' => 7]);
        $this->app->make(Client::class)->captureReportedException($exception);
        $this->client()->flush();

        $logs = $this->sentLogs();
        $this->assertCount(1, $logs);
        $this->assertSame(7, $logs[0]['context']['order_id']);
    }

    public function test_the_hook_can_be_turned_off(): void
    {
        $this->withoutTheChannel();
        config(['elgiosoft-logger.capture_exceptions' => false]);
        $this->fakeCollector();

        report(new RuntimeException('not captured'));
        $this->client()->flush();

        $this->assertSame([], $this->sentLogs());
    }
}
