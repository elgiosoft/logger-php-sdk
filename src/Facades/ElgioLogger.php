<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Facades;

use Elgiosoft\Logger\Client;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void setUser(array{id?: int|string, email?: string, username?: string, ip_address?: string}|null $user)
 * @method static void setTag(string $key, mixed $value)
 * @method static void setTags(array<string, mixed> $tags)
 * @method static void setContext(string $key, array<array-key, mixed>|null $context)
 * @method static string|null log(string $level, string $message, array<array-key, mixed> $context = [], string|null $event = null)
 * @method static string|null event(string $event, string $message, array<array-key, mixed> $context = [], string $level = 'info')
 * @method static string|null captureException(\Throwable $exception, array<array-key, mixed> $context = [], string $level = 'error')
 * @method static \Elgiosoft\Logger\Tracing\Span startSpan(string $name, string $op = 'function', array<string, mixed> $attributes = [], string $kind = 'internal')
 * @method static mixed trace(string $name, callable $callback, string $op = 'function', array<string, mixed> $attributes = [])
 * @method static string|null traceId()
 * @method static string|null traceparent()
 * @method static array<string, string> traceHeaders()
 * @method static string continueTrace(string|null $traceparent)
 * @method static callable guzzleMiddleware()
 * @method static void flush()
 * @method static \Elgiosoft\Logger\Tracing\Tracer tracer()
 * @method static bool isEnabled()
 *
 * @see Client
 */
final class ElgioLogger extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
