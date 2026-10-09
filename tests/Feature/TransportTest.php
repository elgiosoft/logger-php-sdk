<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Tests\Feature;

use Elgiosoft\Logger\Client;
use Elgiosoft\Logger\Facades\ElgioLogger;
use Elgiosoft\Logger\Jobs\SendBatch;
use Elgiosoft\Logger\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class TransportTest extends TestCase
{
    /**
     * Drop what a client still buffers: its logger flushes it whenever PHP destroys it, which would
     * otherwise land in whichever test runs then.
     */
    protected function tearDown(): void
    {
        if ($this->app?->resolved(Client::class)) {
            $this->app->make(Client::class)->drainEnvelopes();
        }

        parent::tearDown();
    }

    private function useTransport(string $transport, array $extra = []): void
    {
        config(['elgiosoft-logger.transport' => $transport] + $extra);
        $this->app->forgetInstance(Client::class);
        $this->app->forgetInstance(\Elgiosoft\Logger\Transport\HttpTransport::class);
        Log::forgetChannel('elgiosoft');
    }

    public function test_deferred_transport_buffers_until_flush(): void
    {
        $this->fakeCollector();

        Log::info('one');
        Log::info('two');

        Http::assertNothingSent();
        $this->assertCount(2, $this->client()->pendingLogs());

        $this->client()->flush();

        $this->assertCount(1, $this->sentEnvelopes());
        $this->assertSame(['one', 'two'], array_column($this->sentLogs(), 'message'));
        $this->assertSame([], $this->client()->pendingLogs());
    }

    public function test_envelope_names_the_service_from_config_or_app_name(): void
    {
        config(['app.name' => 'Yankap API']);
        $this->useTransport('sync');
        $this->fakeCollector();

        Log::info('default service');
        $this->assertSame('Yankap API', $this->sentEnvelopes()[0]['service']);

        $this->useTransport('sync', ['elgiosoft-logger.service' => 'elgiopay']);
        Log::info('explicit service');
        $this->assertSame('elgiopay', $this->sentEnvelopes()[1]['service']);
    }

    public function test_buffer_flushes_when_full(): void
    {
        $this->useTransport('deferred', ['elgiosoft-logger.max_buffer' => 3]);
        $this->fakeCollector();

        foreach (range(1, 7) as $i) {
            Log::info("log {$i}");
        }

        $this->assertCount(2, $this->sentEnvelopes());
        $this->assertCount(1, $this->client()->pendingLogs());
    }

    public function test_sync_transport_sends_immediately(): void
    {
        $this->useTransport('sync');
        $this->fakeCollector();

        Log::warning('right away');

        $this->assertSame(['right away'], array_column($this->sentLogs(), 'message'));
    }

    public function test_app_termination_flushes_the_buffer(): void
    {
        $this->fakeCollector();

        Log::info('before terminate');
        $this->app->terminate();

        $this->assertSame(['before terminate'], array_column($this->sentLogs(), 'message'));
    }

    public function test_queue_transport_dispatches_a_send_batch_job(): void
    {
        $this->useTransport('queue', ['elgiosoft-logger.queue.queue' => 'logs', 'elgiosoft-logger.queue.connection' => 'redis']);
        Bus::fake();

        Log::error('queued');
        $this->client()->flush();

        Bus::assertDispatched(SendBatch::class, function (SendBatch $job): bool {
            return $job->queue === 'logs'
                && $job->connection === 'redis'
                && $job->envelope['logs'][0]['message'] === 'queued';
        });
    }

    public function test_send_batch_job_posts_the_envelope(): void
    {
        $this->useTransport('queue');
        $this->fakeCollector();

        Log::error('through the queue');
        $this->client()->flush();

        // queue.default is "sync" in tests, so the job already ran.
        $this->assertSame(['through the queue'], array_column($this->sentLogs(), 'message'));
    }

    public function test_gzip_compression(): void
    {
        $this->useTransport('deferred', ['elgiosoft-logger.compress' => true]);
        $this->fakeCollector();

        Log::info('compressed');
        $this->client()->flush();

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Content-Encoding', 'gzip'));
        $this->assertSame(['compressed'], array_column($this->sentLogs(), 'message'));
    }

    public function test_collector_errors_never_reach_the_app(): void
    {
        $this->fakeCollector(500);

        Log::error('collector is broken');
        $this->client()->flush();

        $this->assertCount(1, $this->sentEnvelopes());
        $this->assertTrue($this->fallbackHandler()->hasWarningThatContains('collector responded 500'));
    }

    public function test_connection_failures_never_reach_the_app(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        Log::error('collector is down');
        $this->client()->flush();
        ElgioLogger::captureException(new \RuntimeException('still fine'));
        $this->client()->flush();

        $this->assertTrue($this->fallbackHandler()->hasWarningThatContains('could not reach collector: Connection refused'));
        $this->assertSame([], $this->client()->pendingLogs());
    }

    public function test_fallback_channel_pointing_to_the_sdk_does_not_loop(): void
    {
        $this->useTransport('deferred', ['elgiosoft-logger.fallback_channel' => 'elgiosoft']);
        $this->fakeCollector(503);

        Log::error('first');
        $this->client()->flush();

        $this->assertCount(1, $this->sentEnvelopes());
        $this->assertSame([], $this->client()->pendingLogs());
    }

    public function test_scope_data_is_attached_to_logs(): void
    {
        $this->fakeCollector();

        ElgioLogger::setUser(['id' => 15, 'email' => 'jane@example.com']);
        ElgioLogger::setTag('provider', 'pawapay');
        ElgioLogger::setContext('wallet', ['currency' => 'XAF', 'token' => 'abc']);
        ElgioLogger::event('withdrawal.pending', 'Withdrawal pending for 5 minutes', ['amount' => 50000], 'warning');
        ElgioLogger::flush();

        $log = $this->sentLogs()[0];
        $this->assertSame('warning', $log['level']);
        $this->assertSame('withdrawal.pending', $log['event']);
        $this->assertSame(['id' => '15', 'email' => 'jane@example.com'], $log['user']);
        $this->assertSame(['provider' => 'pawapay'], $log['tags']);
        $this->assertSame(['currency' => 'XAF', 'token' => '[Filtered]'], $log['context']['wallet']);
        $this->assertSame(50000, $log['context']['amount']);
    }

    public function test_facade_respects_minimum_level(): void
    {
        $this->useTransport('deferred', ['elgiosoft-logger.level' => 'warning']);
        $this->fakeCollector();

        ElgioLogger::event('noise', 'debug noise', [], 'debug');
        ElgioLogger::event('important', 'important', [], 'error');
        ElgioLogger::flush();

        $this->assertSame(['important'], array_column($this->sentLogs(), 'event'));
    }
}
