<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Transport;

use Closure;
use Elgiosoft\Logger\Jobs\SendBatch;
use Illuminate\Contracts\Bus\Dispatcher;
use Throwable;

/**
 * Pushes envelopes onto the application's own queue; a worker then delivers them over HTTP.
 * This keeps the request path free of network calls to the collector.
 */
final class QueueTransport implements Transport
{
    /**
     * @param  array<string, mixed>  $config
     * @param  Closure(string): void  $onFailure
     */
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly array $config,
        private readonly Closure $onFailure,
    ) {}

    public function send(array $envelope): void
    {
        try {
            $job = new SendBatch($envelope);
            $connection = $this->config['queue']['connection'] ?? null;
            $queue = $this->config['queue']['queue'] ?? null;

            if ($connection) {
                $job->onConnection($connection);
            }

            if ($queue) {
                $job->onQueue($queue);
            }

            $this->dispatcher->dispatch($job);
        } catch (Throwable $exception) {
            ($this->onFailure)('could not queue batch: '.$exception->getMessage());
        }
    }
}
