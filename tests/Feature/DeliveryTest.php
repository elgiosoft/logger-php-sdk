<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Tests\Feature;

use Elgiosoft\Logger\Client;
use Elgiosoft\Logger\Jobs\SendBatch;
use Elgiosoft\Logger\Tests\TestCase;
use Elgiosoft\Logger\Transport\HttpTransport;
use Elgiosoft\Logger\Transport\Spool;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class DeliveryTest extends TestCase
{
    protected function tearDown(): void
    {
        if ($this->app?->resolved(Client::class)) {
            $this->app->make(Client::class)->drainEnvelopes();
        }

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function useTransport(string $transport, array $extra = []): void
    {
        config(['elgiosoft-logger.transport' => $transport] + $extra);
        $this->app->forgetInstance(Client::class);
        $this->app->forgetInstance(HttpTransport::class);
        Log::forgetChannel('elgiosoft');
    }

    /**
     * @param  list<array{0: int, 1?: array<string, string>}>  $responses  Status and headers, in order.
     */
    private function collectorAnswers(array $responses): void
    {
        $sequence = Http::sequence();

        foreach ($responses as $response) {
            $sequence->push(['accepted' => true], $response[0], $response[1] ?? []);
        }

        Http::fake([self::ENDPOINT.'/*' => $sequence, '*' => Http::response(['ok' => true])]);
    }

    private function spool(): Spool
    {
        return new Spool(self::spoolPath());
    }

    public function test_transient_failures_are_retried_with_backoff(): void
    {
        $this->collectorAnswers([[503], [502], [202]]);

        Log::error('eventually delivered');
        $this->client()->flush();

        $this->assertCount(3, $this->sentEnvelopes());
        $this->assertCount(2, $this->sleeps);
        $this->assertGreaterThanOrEqual(0.125, $this->sleeps[0]);
        $this->assertLessThanOrEqual(0.25, $this->sleeps[0]);
        $this->assertGreaterThanOrEqual(0.25, $this->sleeps[1]);
        $this->assertLessThanOrEqual(0.5, $this->sleeps[1]);
        $this->assertSame(0, $this->spool()->count());
        $this->assertFalse($this->fallbackHandler()->hasWarningRecords());
    }

    public function test_connection_errors_are_retried(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            if (++$calls === 1) {
                throw new ConnectionException('Connection refused');
            }

            return Http::response(['accepted' => true], 202);
        });

        Log::error('second try');
        $this->client()->flush();

        $this->assertSame(2, $calls);
        $this->assertSame(['second try'], array_column($this->sentLogs(), 'message'));
    }

    public function test_retry_after_is_honoured_within_the_budget(): void
    {
        $this->collectorAnswers([[429, ['Retry-After' => '2']], [202]]);

        Log::error('rate limited');
        $this->client()->flush();

        $this->assertSame([2.0], $this->sleeps);
        $this->assertCount(2, $this->sentEnvelopes());
    }

    public function test_a_retry_after_beyond_the_budget_spools_the_batch_and_the_next_flush_replays_it(): void
    {
        $this->collectorAnswers([[429, ['Retry-After' => '60']], [202], [202]]);

        Log::error('over quota');
        $this->client()->flush();

        $this->assertSame([], $this->sleeps, 'never retried sooner than Retry-After');
        $this->assertSame(1, $this->spool()->count());
        $this->assertTrue($this->fallbackHandler()->hasWarningThatContains('collector responded 429'));
        $this->assertTrue($this->fallbackHandler()->hasWarningThatContains('spooled for retry'));

        Log::error('collector is back');
        $this->client()->flush();

        $this->assertSame(['over quota', 'collector is back', 'over quota'], array_column($this->sentLogs(), 'message'));
        $this->assertSame(0, $this->spool()->count());
    }

    public function test_client_errors_are_dropped_without_retry(): void
    {
        $this->collectorAnswers([[422], [202]]);

        Log::error('invalid');
        $this->client()->flush();

        $this->assertCount(1, $this->sentEnvelopes());
        $this->assertSame(0, $this->spool()->count());
        $this->assertTrue($this->fallbackHandler()->hasWarningThatContains('dropping batch: collector responded 422'));
    }

    public function test_too_large_batches_are_split_in_half(): void
    {
        $this->collectorAnswers([[413], [202], [413], [202], [202]]);

        foreach (range(1, 5) as $i) {
            Log::info("log {$i}");
        }

        $this->client()->flush();

        $sizes = array_map(fn (array $envelope): int => count($envelope['logs'] ?? []), $this->sentEnvelopes());
        $this->assertSame([5, 3, 2, 1, 1], $sizes);
        $delivered = array_map(fn (array $envelope): array => array_column($envelope['logs'], 'message'), array_slice($this->sentEnvelopes(), 1));
        $this->assertSame([['log 1', 'log 2', 'log 3'], ['log 4', 'log 5'], ['log 4'], ['log 5']], $delivered);
    }

    public function test_sync_mode_does_not_retry_but_spools(): void
    {
        $this->useTransport('sync');
        $this->collectorAnswers([[503], [202]]);

        Log::error('sync failure');

        $this->assertCount(1, $this->sentEnvelopes());
        $this->assertSame([], $this->sleeps);
        $this->assertSame(1, $this->spool()->count());
    }

    public function test_retries_and_budget_are_configurable(): void
    {
        $this->useTransport('deferred', ['elgiosoft-logger.retry.max_retries' => 0]);
        $this->collectorAnswers([[503], [202]]);

        Log::error('no retry');
        $this->client()->flush();

        $this->assertCount(1, $this->sentEnvelopes());
        $this->assertSame(1, $this->spool()->count());
    }

    public function test_failed_batches_go_to_the_queue_when_a_queue_connection_is_configured(): void
    {
        Bus::fake();
        $this->useTransport('deferred', ['elgiosoft-logger.queue.connection' => 'redis', 'elgiosoft-logger.queue.queue' => 'logs']);
        $this->collectorAnswers([[503], [503], [503]]);

        Log::error('queued after failing');
        $this->client()->flush();

        Bus::assertDispatched(SendBatch::class, fn (SendBatch $job): bool => $job->connection === 'redis'
            && $job->envelope['logs'][0]['message'] === 'queued after failing');
        $this->assertSame(0, $this->spool()->count());
    }

    public function test_batches_are_dropped_and_reported_when_the_spool_is_off(): void
    {
        $this->useTransport('deferred', ['elgiosoft-logger.spool.enabled' => false]);
        $this->collectorAnswers([[503], [503], [503]]);

        Log::error('lost');
        $this->client()->flush();

        $this->assertTrue($this->fallbackHandler()->hasWarningThatContains('dropping batch (1 logs, 0 spans)'));
    }

    public function test_send_batch_releases_with_retry_after_on_429(): void
    {
        $job = $this->fakeQueuedJob();
        $this->collectorAnswers([[429, ['Retry-After' => '45']]]);

        $this->app->call([$job, 'handle']);

        $job->assertReleased(45);
    }

    public function test_send_batch_releases_with_jittered_backoff_on_server_errors(): void
    {
        $job = $this->fakeQueuedJob();
        $this->collectorAnswers([[500]]);

        $this->app->call([$job, 'handle']);

        $job->assertReleased();
        $delay = $job->job->releaseDelay;
        $this->assertGreaterThanOrEqual(10, $delay);
        $this->assertLessThanOrEqual(20, $delay);
    }

    public function test_send_batch_drops_client_errors_without_retry(): void
    {
        $job = $this->fakeQueuedJob();
        $this->collectorAnswers([[401]]);

        $this->app->call([$job, 'handle']);

        $job->assertNotReleased();
        $this->assertTrue($this->fallbackHandler()->hasWarningThatContains('dropping batch: collector responded 401'));
    }

    public function test_send_batch_retries_request_timeouts(): void
    {
        $job = $this->fakeQueuedJob();
        $this->collectorAnswers([[408]]);

        $this->app->call([$job, 'handle']);

        $job->assertReleased();
    }

    public function test_send_batch_splits_too_large_batches_into_two_jobs(): void
    {
        $job = $this->fakeQueuedJob(['logs' => [['message' => 'a'], ['message' => 'b'], ['message' => 'c']]]);
        $job->onConnection('redis')->onQueue('logs');
        Bus::fake();
        $this->collectorAnswers([[413]]);

        $this->app->call([$job, 'handle']);

        $job->assertNotReleased();
        Bus::assertDispatchedTimes(SendBatch::class, 2);
        Bus::assertDispatched(SendBatch::class, fn (SendBatch $half): bool => count($half->envelope['logs']) === 2 && $half->connection === 'redis' && $half->queue === 'logs');
        Bus::assertDispatched(SendBatch::class, fn (SendBatch $half): bool => count($half->envelope['logs']) === 1);
    }

    /**
     * @param  array<string, mixed>|null  $envelope
     */
    private function fakeQueuedJob(?array $envelope = null): SendBatch
    {
        if (! method_exists(SendBatch::class, 'withFakeQueueInteractions')) {
            $this->markTestSkipped('withFakeQueueInteractions() needs Laravel 11+.');
        }

        $job = new SendBatch($envelope ?? ['logs' => [['message' => 'queued']]]);
        $job->withFakeQueueInteractions();

        return $job;
    }
}
