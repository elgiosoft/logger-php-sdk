<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Integrations;

use Elgiosoft\Logger\Client;
use Elgiosoft\Logger\Support\QueryParams;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Str;
use Throwable;

/**
 * Records breadcrumbs (recent activity attached to errors) from Laravel's events:
 *  - every log record written through Laravel's logger, whatever its channel or level,
 *  - database queries (the SQL; bindings only when tracing.db_bindings allows them, redacted),
 *  - cache hits and misses (off by default),
 *  - artisan commands (not tracing.ignore_commands); a top-level command starts with an empty buffer.
 *
 * Outgoing HTTP calls and queue jobs are recorded by their own integrations.
 */
final class BreadcrumbIntegration
{
    private int $commandDepth = 0;

    public function __construct(
        private readonly Client $client,
        private readonly bool $runningInConsole,
    ) {}

    public function register(Dispatcher $events): void
    {
        $events->listen(MessageLogged::class, fn (MessageLogged $event) => $this->logged($event));
        $events->listen(QueryExecuted::class, fn (QueryExecuted $event) => $this->query($event));
        $events->listen(CacheHit::class, fn (CacheHit $event) => $this->cache('hit', $event->key));
        $events->listen(CacheMissed::class, fn (CacheMissed $event) => $this->cache('miss', $event->key));
        $events->listen(CommandStarting::class, fn (CommandStarting $event) => $this->commandStarting($event));
        $events->listen(CommandFinished::class, fn () => $this->commandDepth = max(0, $this->commandDepth - 1));
    }

    private function logged(MessageLogged $event): void
    {
        if (! $this->client->recordsBreadcrumbs('logs')) {
            return;
        }

        $exception = $event->context['exception'] ?? null;

        $this->client->addBreadcrumb(
            'log',
            (string) $event->message,
            (string) $event->level,
            $exception instanceof Throwable ? ['exception' => $exception::class] : [],
        );
    }

    private function query(QueryExecuted $query): void
    {
        if (! $this->client->recordsBreadcrumbs('db_queries')) {
            return;
        }

        try {
            $data = [
                'connection' => $query->connectionName,
                'duration_ms' => $query->time,
            ];

            if (($this->client->config()['tracing']['db_bindings'] ?? true) && $query->bindings !== []) {
                $data['params'] = QueryParams::from($query->sql, $query->connection->prepareBindings($query->bindings), $this->client->redactor());
            }

            $this->client->addBreadcrumb('query', preg_replace('/\s+/', ' ', trim($query->sql)) ?? $query->sql, 'info', $data);
        } catch (Throwable $exception) {
            $this->client->reportFailure('query breadcrumb failed: '.$exception->getMessage());
        }
    }

    private function cache(string $result, string $key): void
    {
        if ($this->client->recordsBreadcrumbs('cache')) {
            $this->client->addBreadcrumb('cache', 'cache '.$result.' '.$key, 'info', ['key' => $key, 'hit' => $result === 'hit']);
        }
    }

    private function commandStarting(CommandStarting $event): void
    {
        $topLevel = $this->runningInConsole && $this->commandDepth === 0;
        $this->commandDepth++;

        if ($event->command === null || ! $this->client->recordsBreadcrumbs('console')
            || Str::is((array) ($this->client->config()['tracing']['ignore_commands'] ?? []), $event->command)) {
            return;
        }

        if ($topLevel) {
            $this->client->clearBreadcrumbs();
        }

        $this->client->addBreadcrumb('console', 'artisan '.$event->command, 'info', ['command' => $event->command]);
    }
}
