<?php

declare(strict_types=1);

namespace Elgiosoft\Logger;

use Elgiosoft\Logger\Integrations\HttpClientIntegration;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * Guzzle middleware for clients that don't go through Laravel's Http facade
 * (e.g. elgiosoft/elgiopay-php-sdk, any `new GuzzleHttp\Client`).
 *
 *     $stack = HandlerStack::create();
 *     $stack->push(\Elgiosoft\Logger\GuzzleMiddleware::create(), 'elgiosoft_logger');
 *     new Client(['handler' => $stack, ...]);
 *
 * It injects the W3C `traceparent` header (so the called service joins the same trace) and records an
 * `http.client` span. It resolves the logger lazily on every request and silently does nothing when
 * the package isn't booted, disabled, or no trace is active — so SDKs can push it unconditionally.
 */
final class GuzzleMiddleware
{
    /**
     * @return callable(callable): callable
     */
    public static function create(): callable
    {
        return static function (callable $handler): callable {
            return static function (RequestInterface $request, array $options) use ($handler) {
                $integration = self::integration();

                return $integration === null
                    ? $handler($request, $options)
                    : $integration->middleware($handler)($request, $options);
            };
        };
    }

    private static function integration(): ?HttpClientIntegration
    {
        try {
            if (! function_exists('app') || ! app()->bound(Client::class)) {
                return null;
            }

            return app(HttpClientIntegration::class);
        } catch (Throwable) {
            return null;
        }
    }
}
