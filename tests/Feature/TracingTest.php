<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Tests\Feature;

use Elgiosoft\Logger\Facades\ElgioLogger;
use Elgiosoft\Logger\Tests\Fixtures\ProcessPayout;
use Elgiosoft\Logger\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use RuntimeException;

final class TracingTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        Route::get('/orders/{id}', function (string $id) {
            Log::info('Loading order', ['order_id' => $id]);

            return ['id' => $id];
        })->name('orders.show');

        Route::get('/explode', function () {
            throw new RuntimeException('Kaboom');
        });

        Route::get('/calls-provider', function () {
            Http::get('https://api.provider.test/v1/status');

            return 'ok';
        });

        Route::get('/wallet-queries', function () {
            DB::statement('create table if not exists wallets (id integer primary key, user_id integer, pin text, balance integer)');
            DB::insert('insert into wallets (user_id, pin, balance) values (?, ?, ?), (?, ?, ?)', [7, '1234', 500, 8, '9999', 10]);
            DB::select('select * from wallets where user_id = ?', [7]);
            DB::update('update wallets set balance = ? where balance < ?', [0, 100]);
            DB::select('select * from wallets where user_id = ?', [404]);

            return 'ok';
        });

        Route::get('/queries', function () {
            DB::select('select 1 as one');

            return 'ok';
        });

        Route::get('/dispatches', function () {
            ProcessPayout::dispatch('txn_42');

            return 'ok';
        });
    }

    public function test_request_continues_incoming_traceparent_and_records_root_span(): void
    {
        $this->fakeCollector();
        $traceId = '4bf92f3577b34da6a3ce929d0e0e4736';
        $parentId = '00f067aa0ba902b7';

        $response = $this->get('/orders/42', ['traceparent' => "00-{$traceId}-{$parentId}-01"]);

        $response->assertOk();
        $response->assertHeader('X-Trace-Id', $traceId);
        $this->app->terminate();

        $spans = $this->sentSpans();
        $root = collect($spans)->firstWhere('op', 'http.server');

        $this->assertNotNull($root);
        $this->assertSame($traceId, $root['trace_id']);
        $this->assertSame($parentId, $root['parent_span_id']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $root['span_id']);
        $this->assertSame('GET /orders/{id}', $root['name']);
        $this->assertSame('server', $root['kind']);
        $this->assertSame('ok', $root['status']);
        $this->assertSame(200, $root['status_code']);
        $this->assertSame('/orders/{id}', $root['attributes']['http.route']);
        $this->assertSame('orders.show', $root['attributes']['http.route_name']);
        $this->assertIsFloat($root['duration_ms'] + 0.0);

        $log = collect($this->sentLogs())->firstWhere('message', 'Loading order');
        $this->assertSame($traceId, $log['trace_id']);
        $this->assertSame($root['span_id'], $log['span_id']);
        $this->assertSame('GET', $log['http']['method']);
        $this->assertSame('/orders/{id}', $log['http']['route']);
        $this->assertNotEmpty($log['request_id']);
    }

    public function test_new_trace_is_started_without_traceparent(): void
    {
        $this->fakeCollector();

        $response = $this->get('/orders/7');
        $this->app->terminate();

        $traceId = $response->headers->get('X-Trace-Id');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $traceId);
        $root = collect($this->sentSpans())->firstWhere('op', 'http.server');
        $this->assertNull($root['parent_span_id']);
        $this->assertSame($traceId, $root['trace_id']);
    }

    public function test_server_errors_mark_the_root_span_as_error(): void
    {
        $this->fakeCollector();

        $this->get('/explode')->assertStatus(500);
        $this->app->terminate();

        $root = collect($this->sentSpans())->firstWhere('op', 'http.server');
        $this->assertSame('error', $root['status']);
        $this->assertSame(500, $root['status_code']);

        $error = collect($this->sentLogs())->firstWhere('message', 'Kaboom');
        $this->assertNotNull($error);
        $this->assertSame($root['trace_id'], $error['trace_id']);
        $this->assertSame(RuntimeException::class, $error['exception']['values'][0]['type']);
    }

    public function test_outgoing_http_calls_carry_traceparent_and_create_client_spans(): void
    {
        $this->fakeCollector();

        $response = $this->get('/calls-provider');
        $this->app->terminate();
        $traceId = $response->headers->get('X-Trace-Id');

        $client = collect($this->sentSpans())->firstWhere('op', 'http.client');
        $this->assertNotNull($client);
        $this->assertSame('GET api.provider.test/v1/status', $client['name']);
        $this->assertSame('client', $client['kind']);
        $this->assertSame(200, $client['status_code']);

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.provider.test')
            && $request->header('traceparent')[0] === "00-{$traceId}-{$client['span_id']}-01");

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), self::ENDPOINT) && ! $request->hasHeader('traceparent'));
    }

    public function test_database_queries_are_recorded_as_spans(): void
    {
        $this->fakeCollector();

        $this->get('/queries');
        $this->app->terminate();

        $query = collect($this->sentSpans())->firstWhere('op', 'db.query');
        $this->assertNotNull($query);
        $this->assertSame('select 1 as one', $query['name']);
        $this->assertSame('sqlite', $query['attributes']['db.system']);
        $root = collect($this->sentSpans())->firstWhere('op', 'http.server');
        $this->assertSame($root['span_id'], $query['parent_span_id']);
    }

    public function test_queued_jobs_continue_the_dispatching_trace(): void
    {
        $this->fakeCollector();

        $response = $this->get('/dispatches');
        $this->app->terminate();
        $traceId = $response->headers->get('X-Trace-Id');

        $spans = collect($this->sentSpans());
        $publish = $spans->firstWhere('op', 'queue.publish');
        $job = $spans->firstWhere('op', 'queue.job');
        $root = $spans->firstWhere('op', 'http.server');

        $this->assertNotNull($publish);
        $this->assertNotNull($job);
        $this->assertSame($traceId, $job['trace_id']);
        $this->assertSame($publish['span_id'], $job['parent_span_id']);
        $this->assertSame($root['span_id'], $publish['parent_span_id']);
        $this->assertSame(ProcessPayout::class, $job['name']);
        $this->assertSame('consumer', $job['kind']);

        $log = collect($this->sentLogs())->firstWhere('message', 'Processing payout');
        $this->assertSame($traceId, $log['trace_id']);
        $this->assertSame($job['span_id'], $log['span_id']);
        $this->assertSame('txn_42', $log['transaction_id']);

        // The request's trace state was restored after the synchronous job finished.
        $this->assertSame('ok', $root['status']);
    }

    public function test_manual_spans_and_trace_helper(): void
    {
        $this->fakeCollector();

        $result = ElgioLogger::trace('payout.process', function () {
            $child = ElgioLogger::startSpan('maviance.cashout', 'http.client', ['provider' => 'maviance', 'api_key' => 'secret']);
            $child->finish();

            return 'done';
        });

        $this->assertSame('done', $result);
        $traceId = ElgioLogger::traceId();
        ElgioLogger::flush();

        $spans = collect($this->sentSpans());
        $parent = $spans->firstWhere('name', 'payout.process');
        $child = $spans->firstWhere('name', 'maviance.cashout');

        $this->assertSame($traceId, $parent['trace_id']);
        $this->assertSame('ok', $parent['status']);
        $this->assertSame($parent['span_id'], $child['parent_span_id']);
        $this->assertSame('[Filtered]', $child['attributes']['api_key']);
    }

    public function test_trace_helper_marks_span_as_error_and_rethrows(): void
    {
        $this->fakeCollector();

        try {
            ElgioLogger::trace('failing', fn () => throw new RuntimeException('nope'));
            $this->fail('Exception was swallowed');
        } catch (RuntimeException) {
            // expected
        }

        ElgioLogger::flush();
        $this->assertSame('error', $this->sentSpans()[0]['status']);
    }

    public function test_unsampled_traces_send_logs_but_no_spans(): void
    {
        $this->fakeCollector();
        $traceId = '4bf92f3577b34da6a3ce929d0e0e4736';

        $this->get('/orders/1', ['traceparent' => "00-{$traceId}-00f067aa0ba902b7-00"]);
        $this->app->terminate();

        $this->assertSame([], $this->sentSpans());
        $this->assertSame($traceId, $this->sentLogs()[0]['trace_id']);
    }

    public function test_traceparent_helpers_propagate_the_trace_by_hand(): void
    {
        $this->fakeCollector();

        $header = ElgioLogger::traceparent();
        $this->assertMatchesRegularExpression('/^00-[0-9a-f]{32}-[0-9a-f]{16}-0[01]$/', $header);
        $traceId = ElgioLogger::traceId();
        $this->assertSame($traceId, substr($header, 3, 32));

        ElgioLogger::trace('POST node-service/charge', function ($span) use ($traceId): void {
            $headers = ElgioLogger::traceHeaders();

            $this->assertSame("00-{$traceId}-{$span->spanId}-01", $headers['traceparent']);
        }, 'http.client');
    }

    public function test_continue_trace_joins_an_incoming_trace(): void
    {
        $this->fakeCollector();
        $incoming = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', ElgioLogger::continueTrace($incoming));

        ElgioLogger::trace('consume payment.settled', fn () => Log::info('consumed'), 'queue.job');
        $this->client()->flush();

        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $this->sentLogs()[0]['trace_id']);
        $this->assertSame('00f067aa0ba902b7', $this->sentSpans()[0]['parent_span_id']);

        $this->assertNotSame('4bf92f3577b34da6a3ce929d0e0e4736', ElgioLogger::continueTrace('garbage'));
    }

    public function test_helpers_return_nothing_when_disabled(): void
    {
        config(['elgiosoft-logger.key' => null]);
        $this->app->forgetInstance(\Elgiosoft\Logger\Client::class);

        $this->assertNull(ElgioLogger::traceparent());
        $this->assertSame([], ElgioLogger::traceHeaders());
    }

    /**
     * @param  list<array<string, mixed>>  $history
     */
    private function rawGuzzle(array &$history): \GuzzleHttp\Client
    {
        $stack = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([new \GuzzleHttp\Psr7\Response(201), new \GuzzleHttp\Psr7\Response(503)]));
        $stack->push(\Elgiosoft\Logger\GuzzleMiddleware::create(), 'elgiosoft_logger');
        $stack->push(\GuzzleHttp\Middleware::history($history));

        return new \GuzzleHttp\Client(['handler' => $stack, 'base_uri' => 'https://api.elgiopay.test', 'http_errors' => false]);
    }

    public function test_guzzle_middleware_propagates_the_trace_from_raw_guzzle_clients(): void
    {
        $this->fakeCollector();
        $history = [];
        $guzzle = $this->rawGuzzle($history);

        ElgioLogger::trace('POST /api/v1/wallet/add-funds', function () use ($guzzle): void {
            $guzzle->post('/api/v1/payments', ['json' => ['amount' => 100]]);
            $guzzle->get('/api/v1/payments/1');
        }, 'http.server');
        $this->client()->flush();

        $traceId = $this->sentSpans()[0]['trace_id'];
        $clientSpans = collect($this->sentSpans())->where('op', 'http.client')->values();

        $this->assertCount(2, $clientSpans);
        $this->assertSame('POST api.elgiopay.test/api/v1/payments', $clientSpans[0]['name']);
        $this->assertSame(201, $clientSpans[0]['status_code']);
        $this->assertSame('error', $clientSpans[1]['status']);
        $this->assertSame("00-{$traceId}-{$clientSpans[0]['span_id']}-01", $history[0]['request']->getHeaderLine('traceparent'));
        $this->assertSame("00-{$traceId}-{$clientSpans[1]['span_id']}-01", $history[1]['request']->getHeaderLine('traceparent'));
    }

    public function test_guzzle_middleware_is_inert_without_a_trace_or_when_disabled(): void
    {
        $this->fakeCollector();
        $history = [];
        $guzzle = $this->rawGuzzle($history);

        $guzzle->get('/no-trace-yet');
        $this->assertFalse($history[0]['request']->hasHeader('traceparent'));

        config(['elgiosoft-logger.key' => null]);
        $this->app->forgetInstance(\Elgiosoft\Logger\Client::class);
        ElgioLogger::clearResolvedInstance(\Elgiosoft\Logger\Client::class);
        $guzzle->get('/disabled');
        $this->assertFalse($history[1]['request']->hasHeader('traceparent'));
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function walletQuerySpans(): \Illuminate\Support\Collection
    {
        $this->get('/wallet-queries')->assertOk();
        $this->app->terminate();

        return collect($this->sentSpans())->where('op', 'db.query')->values();
    }

    public function test_query_spans_carry_parameters_and_results_with_secrets_redacted(): void
    {
        $this->fakeCollector();
        $spans = $this->walletQuerySpans();

        $insert = $spans->first(fn (array $span): bool => str_starts_with($span['name'], 'insert into wallets'));
        $this->assertSame([
            ['name' => 'user_id', 'value' => 7], ['name' => 'pin', 'value' => '[Filtered]'], ['name' => 'balance', 'value' => 500],
            ['name' => 'user_id', 'value' => 8], ['name' => 'pin', 'value' => '[Filtered]'], ['name' => 'balance', 'value' => 10],
        ], $insert['attributes']['db.params']);

        $select = $spans->first(fn (array $span): bool => $span['name'] === 'select * from wallets where user_id = ?');
        $this->assertSame([['name' => 'user_id', 'value' => 7]], $select['attributes']['db.params']);
        $this->assertSame(1, $select['attributes']['db.rows']);
        $this->assertSame(7, $select['attributes']['db.result'][0]['user_id']);
        $this->assertSame('[Filtered]', $select['attributes']['db.result'][0]['pin']);
        $this->assertFalse($select['attributes']['db.result_truncated']);

        $update = $spans->first(fn (array $span): bool => str_starts_with($span['name'], 'update wallets'));
        $this->assertSame(1, $update['attributes']['db.rows_affected']);

        $empty = $spans->last(fn (array $span): bool => $span['name'] === 'select * from wallets where user_id = ?');
        $this->assertSame(0, $empty['attributes']['db.rows']);
        $this->assertArrayNotHasKey('db.result', $empty['attributes']);

        $this->assertStringNotContainsString('1234', json_encode($spans->all()));
        $this->assertStringNotContainsString('9999', json_encode($spans->all()));
    }

    protected function withoutQueryCapture($app): void
    {
        $app['config']->set('elgiosoft-logger.tracing.db_bindings', 'false');
        $app['config']->set('elgiosoft-logger.tracing.db_results', 'false');
    }

    #[\Orchestra\Testbench\Attributes\DefineEnvironment('withoutQueryCapture')]
    public function test_parameters_and_results_can_be_turned_off(): void
    {
        $this->fakeCollector();

        $select = $this->walletQuerySpans()->first(fn (array $span): bool => str_starts_with($span['name'], 'select * from wallets'));

        $this->assertArrayNotHasKey('db.params', $select['attributes']);
        $this->assertArrayNotHasKey('db.rows', $select['attributes']);
    }
}
