<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Jobs;

use Elgiosoft\Logger\Client;
use Elgiosoft\Logger\Transport\HttpTransport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Delivers one envelope to the collector from a queue worker.
 *
 * Failures never throw (a thrown exception would be reported through the log stack and
 * create another batch, looping while the collector is down). Instead the job releases
 * itself back onto the queue a few times and then gives up.
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

    public function handle(Client $client, HttpTransport $transport): void
    {
        $delivered = $client->withoutRecording(fn (): bool => $transport->post($this->envelope));

        if ($delivered || $this->job === null) {
            return;
        }

        $maxAttempts = max(1, (int) ($client->config()['queue']['tries'] ?? 3));

        if ($this->attempts() < $maxAttempts) {
            $this->release(min(300, 10 * (2 ** ($this->attempts() - 1))));

            return;
        }

        $client->reportFailure(sprintf('dropping batch after %d attempts', $this->attempts()));
    }

    public function displayName(): string
    {
        return 'elgiosoft-logger:send-batch';
    }
}
