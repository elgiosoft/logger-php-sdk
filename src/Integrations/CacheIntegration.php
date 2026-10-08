<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Integrations;

use Elgiosoft\Logger\Client;
use Elgiosoft\Logger\Support\Time;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Records zero-duration "cache" spans for cache hits, misses, writes and deletes.
 */
final class CacheIntegration
{
    public function __construct(private readonly Client $client) {}

    public function register(Dispatcher $events): void
    {
        $events->listen(CacheHit::class, fn (CacheHit $event) => $this->record('cache.get', $event->key, ['cache.hit' => true]));
        $events->listen(CacheMissed::class, fn (CacheMissed $event) => $this->record('cache.get', $event->key, ['cache.hit' => false]));
        $events->listen(KeyWritten::class, fn (KeyWritten $event) => $this->record('cache.put', $event->key, []));
        $events->listen(KeyForgotten::class, fn (KeyForgotten $event) => $this->record('cache.forget', $event->key, []));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function record(string $op, string $key, array $attributes): void
    {
        $tracer = $this->client->tracer();

        if (! $this->client->isRecording() || $tracer->currentSpan() === null) {
            return;
        }

        $now = Time::now();
        $tracer->recordSpan($op.' '.$key, $op, $now, $now, ['cache.key' => $key] + $attributes, 'client');
    }
}
