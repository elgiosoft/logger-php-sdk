<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Integrations;

use Elgiosoft\Logger\Client;
use Elgiosoft\Logger\Database\TracedMariaDbConnection;
use Elgiosoft\Logger\Database\TracedMySqlConnection;
use Elgiosoft\Logger\Database\TracedPostgresConnection;
use Elgiosoft\Logger\Database\TracedSQLiteConnection;
use Elgiosoft\Logger\Database\TracedSqlServerConnection;
use Elgiosoft\Logger\Support\BindingNames;
use Elgiosoft\Logger\Support\Redactor;
use Elgiosoft\Logger\Support\Time;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Throwable;

/**
 * Records a "db.query" span for every query run inside an active trace, with
 *  - its parameters (tracing.db_bindings): values of sensitive columns are redacted by name, and
 *  - its result (tracing.db_results): first rows of a SELECT (sensitive columns redacted) or the
 *    affected-row count of an UPDATE/DELETE, attached once Laravel returns them.
 */
final class DatabaseIntegration
{
    /**
     * Span id of the last recorded query, per connection name.
     *
     * @var array<string, string>
     */
    private array $lastSpan = [];

    public function __construct(private readonly Client $client) {}

    public function register(Dispatcher $events): void
    {
        $events->listen(QueryExecuted::class, function (QueryExecuted $query): void {
            $this->handle($query);
        });
    }

    /**
     * Use result-reporting subclasses of Laravel's connections. Skips any driver another package
     * already customised, so we never replace someone else's connection class.
     */
    public static function registerConnections(): void
    {
        $classes = [
            'mysql' => TracedMySqlConnection::class,
            'pgsql' => TracedPostgresConnection::class,
            'sqlite' => TracedSQLiteConnection::class,
            'sqlsrv' => TracedSqlServerConnection::class,
        ];

        if (class_exists(\Illuminate\Database\MariaDbConnection::class)) {
            $classes['mariadb'] = TracedMariaDbConnection::class;
        }

        foreach ($classes as $driver => $class) {
            if (Connection::getResolver($driver) !== null) {
                continue;
            }

            Connection::resolverFor($driver, static fn ($pdo, $database = '', $prefix = '', array $config = []) => new $class($pdo, $database, $prefix, $config));
        }
    }

    public function handle(QueryExecuted $query): void
    {
        $tracer = $this->client->tracer();
        unset($this->lastSpan[$query->connectionName]);

        if (! $this->client->isRecording() || $tracer->currentSpan() === null || ! $tracer->isSampled()) {
            return;
        }

        $tracing = $this->client->config()['tracing'] ?? [];

        if ($query->time < (float) ($tracing['db_min_duration_ms'] ?? 0)) {
            return;
        }

        try {
            $end = Time::now();
            $connection = $query->connection;
            $attributes = [
                'db.system' => $connection->getDriverName(),
                'db.name' => $connection->getDatabaseName(),
                'db.connection' => $query->connectionName,
                'db.duration_ms' => $query->time,
            ];

            if (($tracing['db_bindings'] ?? true) && $query->bindings !== []) {
                $attributes['db.params'] = $this->params($query->sql, $connection->prepareBindings($query->bindings));
            }

            $span = $tracer->recordSpan(
                mb_substr(preg_replace('/\s+/', ' ', trim($query->sql)) ?? $query->sql, 0, 1000),
                'db.query',
                $end - ($query->time / 1000),
                $end,
                $attributes,
                'client',
            );

            if ($span !== null) {
                $this->lastSpan[$query->connectionName] = $span->spanId;
            }
        } catch (Throwable $exception) {
            $this->client->reportFailure('db span failed: '.$exception->getMessage());
        }
    }

    /**
     * @param  array<int, mixed>  $rows
     */
    public function attachRows(string $connectionName, array $rows): void
    {
        $spanId = $this->takeSpan($connectionName);

        if ($spanId === null) {
            return;
        }

        $tracing = $this->client->config()['tracing'] ?? [];
        $limit = max(0, (int) ($tracing['db_result_rows'] ?? 10));
        $maxBytes = max(256, (int) ($tracing['db_result_max_bytes'] ?? 8192));
        $sample = array_map(static fn (mixed $row): mixed => is_object($row) ? (array) $row : $row, array_slice($rows, 0, $limit));

        $attributes = ['db.rows' => count($rows)];

        if ($sample !== []) {
            $encoded = json_encode($sample, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) ?: '';

            while (strlen($encoded) > $maxBytes && count($sample) > 1) {
                array_pop($sample);
                $encoded = json_encode($sample, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) ?: '';
            }

            $attributes['db.result'] = strlen($encoded) > $maxBytes ? ['_truncated' => true, 'preview' => mb_strcut($encoded, 0, $maxBytes)] : $sample;
            $attributes['db.result_truncated'] = count($sample) < count($rows);
        }

        $this->client->amendSpan($spanId, $attributes);
    }

    public function attachAffected(string $connectionName, int $affected): void
    {
        $spanId = $this->takeSpan($connectionName);

        if ($spanId !== null) {
            $this->client->amendSpan($spanId, ['db.rows_affected' => $affected]);
        }
    }

    private function takeSpan(string $connectionName): ?string
    {
        if (! $this->client->isRecording() || ! ($this->client->config()['tracing']['db_results'] ?? true)) {
            return null;
        }

        $spanId = $this->lastSpan[$connectionName] ?? null;
        unset($this->lastSpan[$connectionName]);

        return $spanId;
    }

    /**
     * @param  array<int|string, mixed>  $bindings
     * @return list<array{name: ?string, value: mixed}>
     */
    private function params(string $sql, array $bindings): array
    {
        $bindings = array_values($bindings);
        $names = BindingNames::infer($sql, count($bindings));
        $redactor = $this->client->redactor();
        $params = [];

        foreach (array_slice($bindings, 0, 100) as $index => $value) {
            $name = $names[$index] ?? null;

            if (($name !== null && $redactor->isSensitive($name)) || $this->looksSecret($value)) {
                $value = Redactor::FILTERED;
            } elseif (is_string($value)) {
                $value = mb_check_encoding($value, 'UTF-8') ? mb_substr($value, 0, 500) : '[binary]';
            } elseif (! is_scalar($value) && $value !== null) {
                $value = get_debug_type($value);
            }

            $params[] = ['name' => $name, 'value' => $value];
        }

        return $params;
    }

    /**
     * Password hashes are secrets even when the column name is unknown.
     */
    private function looksSecret(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\$(2[aby]|argon2i|argon2id)\$/', $value) === 1;
    }
}
