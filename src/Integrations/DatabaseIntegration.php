<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Integrations;

use Elgiosoft\Logger\Client;
use Elgiosoft\Logger\Support\Time;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Throwable;

/**
 * Records a "db.query" span for every query run inside an active trace.
 * Bindings are never sent (they routinely contain personal data).
 */
final class DatabaseIntegration
{
    public function __construct(private readonly Client $client) {}

    public function register(Dispatcher $events): void
    {
        $events->listen(QueryExecuted::class, function (QueryExecuted $query): void {
            $this->handle($query);
        });
    }

    public function handle(QueryExecuted $query): void
    {
        $tracer = $this->client->tracer();

        if (! $this->client->isRecording() || $tracer->currentSpan() === null || ! $tracer->isSampled()) {
            return;
        }

        $threshold = (float) ($this->client->config()['tracing']['db_min_duration_ms'] ?? 0);

        if ($query->time < $threshold) {
            return;
        }

        try {
            $end = Time::now();
            $connection = $query->connection;

            $tracer->recordSpan(
                mb_substr(preg_replace('/\s+/', ' ', trim($query->sql)) ?? $query->sql, 0, 1000),
                'db.query',
                $end - ($query->time / 1000),
                $end,
                [
                    'db.system' => $connection->getDriverName(),
                    'db.name' => $connection->getDatabaseName(),
                    'db.connection' => $query->connectionName,
                    'db.duration_ms' => $query->time,
                ],
                'client',
            );
        } catch (Throwable $exception) {
            $this->client->reportFailure('db span failed: '.$exception->getMessage());
        }
    }
}
