<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Integrations;

use Elgiosoft\Logger\Client;
use Elgiosoft\Logger\Tracing\Span;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;

/**
 * Wraps short-lived artisan commands in a "console.command" trace and flushes when they end.
 * Long-running commands (queue workers, schedulers, servers) are ignored.
 */
final class ConsoleIntegration
{
    /**
     * @var list<Span|null>
     */
    private array $spans = [];

    public function __construct(private readonly Client $client) {}

    public function register(Dispatcher $events): void
    {
        $events->listen(CommandStarting::class, fn (CommandStarting $event) => $this->starting($event));
        $events->listen(CommandFinished::class, fn (CommandFinished $event) => $this->finished($event));
    }

    private function starting(CommandStarting $event): void
    {
        if ($event->command === null || $this->isIgnored($event->command) || ! $this->client->isRecording()) {
            $this->spans[] = null;

            return;
        }

        $tracer = $this->client->tracer();
        $tracer->saveState();
        $tracer->startTrace();
        $this->spans[] = $tracer->startSpan($event->command, 'console.command', ['command' => $event->command], 'internal');
    }

    private function finished(CommandFinished $event): void
    {
        if ($this->spans === []) {
            return;
        }

        $span = array_pop($this->spans);

        if ($span === null) {
            $this->client->flush();

            return;
        }

        $span->setAttribute('exit_code', $event->exitCode);
        $span->setStatus($event->exitCode === 0 ? 'ok' : 'error');
        $span->finish();
        $this->client->flush();
        $this->client->tracer()->restoreState();
    }

    private function isIgnored(string $command): bool
    {
        $patterns = (array) ($this->client->config()['tracing']['ignore_commands'] ?? []);

        return Str::is($patterns, $command);
    }
}
