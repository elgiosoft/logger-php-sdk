<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Tests\Feature;

use Elgiosoft\Logger\Facades\ElgioLogger;
use Elgiosoft\Logger\Tests\Fixtures\DeliverWebhook;
use Elgiosoft\Logger\Tests\TestCase;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

final class BreadcrumbsTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        Route::get('/checkout', function () {
            Log::debug('Loading cart');
            DB::select('select * from (select 1 as pin, 2 as amount) where pin = ? or amount = ?', ['1234', 5000]);
            Http::get('https://api.provider.test/v1/collect?token=abc&msisdn=2376');
            Log::error('Collection failed');

            return 'ok';
        });

        Route::get('/second', function () {
            Log::error('Second request failed');

            return 'ok';
        });

        Route::get('/dispatches', function () {
            Log::info('Before the job');

            try {
                DeliverWebhook::dispatchSync(7);
            } catch (RuntimeException) {
                // reported below with the request's breadcrumbs
            }

            Log::error('After the job');

            return 'ok';
        });
    }

    protected function keepThreeBreadcrumbs($app): void
    {
        $app['config']->set('elgiosoft-logger.breadcrumbs.max', 3);
    }

    protected function withoutBreadcrumbs($app): void
    {
        $app['config']->set('elgiosoft-logger.breadcrumbs.max', 0);
    }

    protected function withoutQueryBindings($app): void
    {
        $app['config']->set('elgiosoft-logger.tracing.db_bindings', false);
    }

    private function useChannelLevel(string $level): void
    {
        config(['logging.channels.elgiosoft.level' => $level]);
        Log::forgetChannel('elgiosoft');
    }

    public function test_errors_carry_the_recent_activity_oldest_first(): void
    {
        if (! method_exists(Factory::class, 'globalMiddleware')) {
            $this->markTestSkipped('Global HTTP client middleware needs Laravel 10+.');
        }

        $this->fakeCollector();
        $this->useChannelLevel('error');

        $this->get('/checkout')->assertOk();
        $this->app->terminate();

        $logs = $this->sentLogs();
        $this->assertSame(['Collection failed'], array_column($logs, 'message'), 'the debug log is below the channel level');

        $breadcrumbs = $logs[0]['context']['breadcrumbs'];
        $this->assertSame(['log', 'query', 'http'], array_column($breadcrumbs, 'category'));

        [$log, $query, $http] = $breadcrumbs;
        $this->assertSame('Loading cart', $log['message']);
        $this->assertSame('debug', $log['level']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $log['timestamp']);

        $this->assertSame('select * from (select 1 as pin, 2 as amount) where pin = ? or amount = ?', $query['message']);
        $this->assertSame([['name' => 'pin', 'value' => '[Filtered]'], ['name' => 'amount', 'value' => 5000]], $query['data']['params']);

        $this->assertSame('GET https://api.provider.test/v1/collect 200', $http['message']);
        $this->assertSame(['method' => 'GET', 'url' => 'https://api.provider.test/v1/collect', 'status_code' => 200], $http['data']);
    }

    public function test_breadcrumbs_are_only_attached_to_errors(): void
    {
        $this->fakeCollector();

        Log::info('first');
        Log::warning('second');
        Log::error('third');
        $this->client()->flush();

        [$first, $second, $third] = $this->sentLogs();
        $this->assertArrayNotHasKey('breadcrumbs', $first['context'] ?? []);
        $this->assertArrayNotHasKey('breadcrumbs', $second['context'] ?? []);
        $this->assertSame(['first', 'second'], array_column($third['context']['breadcrumbs'], 'message'));
    }

    #[DefineEnvironment('keepThreeBreadcrumbs')]
    public function test_the_buffer_keeps_only_the_most_recent_breadcrumbs(): void
    {
        $this->fakeCollector();

        foreach (range(1, 5) as $i) {
            ElgioLogger::log('info', "step {$i}");
        }

        ElgioLogger::captureException(new RuntimeException('boom'));
        $this->client()->flush();

        $error = collect($this->sentLogs())->firstWhere('message', 'boom');
        $this->assertSame(['step 3', 'step 4', 'step 5'], array_column($error['context']['breadcrumbs'], 'message'));
    }

    #[DefineEnvironment('withoutBreadcrumbs')]
    public function test_breadcrumbs_can_be_turned_off(): void
    {
        $this->fakeCollector();

        Log::info('noise');
        Log::error('failure');
        $this->client()->flush();

        $this->assertArrayNotHasKey('breadcrumbs', $this->sentLogs()[1]['context'] ?? []);
    }

    public function test_breadcrumb_messages_and_data_are_redacted(): void
    {
        $this->fakeCollector();

        ElgioLogger::addBreadcrumb('auth', 'Login with password=hunter2', 'info', ['token' => 'abc', 'user_id' => 9]);
        Log::error('Login failed');
        $this->client()->flush();

        $breadcrumb = $this->sentLogs()[0]['context']['breadcrumbs'][0];
        $this->assertSame('Login with password=[Filtered]', $breadcrumb['message']);
        $this->assertSame(['token' => '[Filtered]', 'user_id' => 9], $breadcrumb['data']);
    }

    #[DefineEnvironment('withoutQueryBindings')]
    public function test_query_bindings_follow_the_db_bindings_option(): void
    {
        $this->fakeCollector();

        DB::select('select ? as amount', [5000]);
        Log::error('failure');
        $this->client()->flush();

        $query = $this->sentLogs()[0]['context']['breadcrumbs'][0];
        $this->assertSame('query', $query['category']);
        $this->assertArrayNotHasKey('params', $query['data']);
    }

    public function test_each_request_starts_with_an_empty_buffer(): void
    {
        $this->fakeCollector();
        $this->useChannelLevel('error');

        $this->get('/checkout');
        $this->app->terminate();
        $this->get('/second');
        $this->app->terminate();

        $second = collect($this->sentLogs())->firstWhere('message', 'Second request failed');
        $this->assertArrayNotHasKey('breadcrumbs', $second['context'] ?? []);
    }

    public function test_jobs_get_their_own_buffer_and_restore_the_request_one(): void
    {
        $this->fakeCollector();

        $this->get('/dispatches')->assertOk();
        $this->app->terminate();

        $after = collect($this->sentLogs())->firstWhere('message', 'After the job');
        $this->assertSame(['Before the job'], array_column($after['context']['breadcrumbs'], 'message'));
    }

    public function test_queued_job_exceptions_carry_the_job_breadcrumbs(): void
    {
        $this->fakeCollector();

        try {
            DeliverWebhook::dispatchSync(7);
        } catch (RuntimeException $exception) {
            report($exception);
        }

        $this->client()->flush();

        $error = collect($this->sentLogs())->firstWhere('message', 'Webhook returned 502');
        $this->assertSame('queue', $error['context']['breadcrumbs'][0]['category']);
        $this->assertStringContainsString(DeliverWebhook::class, $error['context']['breadcrumbs'][0]['message']);
    }

    public function test_top_level_commands_start_with_an_empty_buffer(): void
    {
        $this->fakeCollector();
        $input = new ArrayInput([]);
        $output = new NullOutput;

        Log::info('before the command');
        // What `php artisan payouts:run` fires (Artisan::call() does not fire command events on Laravel 11+).
        event(new CommandStarting('payouts:run', $input, $output));
        Log::error('Payout batch failed');
        event(new CommandFinished('payouts:run', $input, $output, 1));
        $this->client()->flush();

        $error = collect($this->sentLogs())->firstWhere('message', 'Payout batch failed');
        $this->assertSame(['artisan payouts:run'], array_column($error['context']['breadcrumbs'], 'message'));
    }
}
