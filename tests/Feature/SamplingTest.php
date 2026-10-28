<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Tests\Feature;

use Elgiosoft\Logger\Facades\ElgioLogger;
use Elgiosoft\Logger\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use RuntimeException;

final class SamplingTest extends TestCase
{
    protected function withoutLogs($app): void
    {
        $app['config']->set('elgiosoft-logger.logs_sample_rate', 0.0);
    }

    protected function withoutErrors($app): void
    {
        $app['config']->set('elgiosoft-logger.errors_sample_rate', '0');
    }

    protected function withHalfTheLogs($app): void
    {
        $app['config']->set('elgiosoft-logger.logs_sample_rate', 0.5);
    }

    public function test_everything_is_kept_by_default(): void
    {
        $this->fakeCollector();

        Log::debug('debug');
        Log::warning('warning');
        Log::error('error');
        $this->client()->flush();

        $this->assertSame(['debug', 'warning', 'error'], array_column($this->sentLogs(), 'message'));
    }

    #[DefineEnvironment('withoutLogs')]
    public function test_logs_sample_rate_applies_below_error(): void
    {
        $this->fakeCollector();

        Log::info('info');
        Log::warning('warning');
        Log::error('error');
        Log::critical('critical');
        $this->client()->flush();

        $logs = $this->sentLogs();
        $this->assertSame(['error', 'critical'], array_column($logs, 'message'));
        // Sampled-away logs still show up as breadcrumbs of the next error.
        $this->assertSame(['info', 'warning'], array_column($logs[0]['context']['breadcrumbs'], 'message'));
    }

    #[DefineEnvironment('withoutErrors')]
    public function test_errors_sample_rate_applies_from_error_up(): void
    {
        $this->fakeCollector();

        Log::info('info');
        Log::error('error');
        ElgioLogger::captureException(new RuntimeException('exception'));
        $this->client()->flush();

        $this->assertSame(['info'], array_column($this->sentLogs(), 'message'));
    }

    #[DefineEnvironment('withoutErrors')]
    public function test_an_exception_sampled_away_by_the_handler_hook_is_not_sent_by_the_channel(): void
    {
        $this->fakeCollector();

        report(new RuntimeException('dropped once, dropped everywhere'));
        $this->client()->flush();

        $this->assertSame([], $this->sentLogs());
    }

    #[DefineEnvironment('withHalfTheLogs')]
    public function test_partial_rates_keep_roughly_that_share(): void
    {
        $this->fakeCollector();
        mt_srand(42);

        foreach (range(1, 400) as $i) {
            ElgioLogger::log('info', "log {$i}");
        }

        $kept = count($this->client()->pendingLogs()) + count($this->sentLogs());
        mt_srand();

        $this->assertGreaterThan(140, $kept);
        $this->assertLessThan(260, $kept);
    }
}
