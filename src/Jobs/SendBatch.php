<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Jobs;

use Elgiosoft\Logger\Client;
use Elgiosoft\Logger\Transport\Delivery;
use Elgiosoft\Logger\Transport\HttpTransport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Delivers one envelope to the collector from a queue worker.
 *
 * Failures never throw (a thrown exception would be reported through the log stack and
 * create another batch, looping while the collector is down). Instead:
 *  - transient failures (connection errors, 5xx, 408) release the job with exponential backoff and
 *    jitter, a 429 releases it for its Retry-After, up to queue.tries attempts;
 *  - a 413 splits the batch into two new jobs;
 *  - any other 4xx is reported and the batch dropped (sending it again cannot succeed).
 */
final class SendBatch implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /**
     * Higher than the configured retry count so the worker never fails the job itself
     * (a MaxAttemptsExceededException would be logged and loop back into the SDK).
     */
    public int $tries = 10;

    public int $timeout = 30;

    /**
     * @param  array<string, mixed>  $envelope
     */
    public function __construct(public array $envelope) {}

    public function handle(Client $client, HttpTransport $transport, Dispatcher $dispatcher): void
    {
        $delivery = $client->withoutRecording(fn (): Delivery => $transport->attempt($this->envelope));

        if ($delivery->delivered()) {
            return;
        }

        if ($delivery->outcome === Delivery::TOO_LARGE) {
            $this->split($client, $dispatcher, $delivery);

            return;
        }

        if ($delivery->outcome === Delivery::REJECTED) {
            $client->reportFailure('dropping batch: '.$delivery->reason);

            return;
        }

        if ($this->job === null) {
            $client->reportFailure($delivery->reason);

            return;
        }

        $maxAttempts = max(1, (int) ($client->config()['queue']['tries'] ?? 3));

        if ($this->attempts() < $maxAttempts) {
            $this->release($this->retryDelay($delivery));

            return;
        }

        $client->reportFailure(sprintf('dropping batch after %d attempts: %s', $this->attempts(), $delivery->reason));
    }

    public function displayName(): string
    {
        return 'elgiosoft-logger:send-batch';
    }

    /**
     * Seconds before the next attempt: the collector's Retry-After when it sent one, otherwise
     * 10–20 s, then 20–40 s, 40–80 s… (at most 10 minutes), the random part spreading the retries.
     */
    public function retryDelay(Delivery $delivery): int
    {
        if ($delivery->retryAfter !== null) {
            return max(1, (int) ceil($delivery->retryAfter));
        }

        return max(1, (int) round(HttpTransport::backoff(max(0, $this->attempts() - 1), 10.0 * 2, 600.0)));
    }

    private function split(Client $client, Dispatcher $dispatcher, Delivery $delivery): void
    {
        $halves = Delivery::split($this->envelope);

        if ($halves === null) {
            $client->reportFailure('dropping batch: '.$delivery->reason);

            return;
        }

        $client->withoutRecording(function () use ($halves, $dispatcher): void {
            foreach ($halves as $half) {
                $job = new self($half);
                $job->connection = $this->connection;
                $job->queue = $this->queue;
                $dispatcher->dispatch($job);
            }
        });
    }
}
