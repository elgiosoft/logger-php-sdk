<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Http\Middleware;

use Closure;
use Elgiosoft\Logger\Client;
use Elgiosoft\Logger\Support\BodyCapture;
use Elgiosoft\Logger\Support\Ids;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Starts (or continues, from a W3C traceparent header) a trace for every HTTP request,
 * records the root "http.server" span and exposes X-Trace-Id / X-Request-Id headers.
 */
final class TraceRequests
{
    public function __construct(
        private readonly Client $client,
        private readonly BodyCapture $capture,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if (! $this->client->isEnabled()) {
            return $next($request);
        }

        $config = $this->client->config();
        $tracer = $this->client->tracer();
        $traceId = $tracer->startTrace($request->headers->get('traceparent'));
        $requestId = $this->requestId($request);

        $this->client->setRequestContext([
            'request_id' => $requestId,
            'http' => $this->httpContext($request, (bool) ($config['send_default_pii'] ?? false)),
        ]);

        $span = null;

        if ((bool) ($config['tracing']['requests'] ?? true) && ! $this->isIgnored($request, (array) ($config['tracing']['ignore_paths'] ?? []))) {
            $span = $tracer->startSpan(
                $request->getMethod().' /'.ltrim($request->path(), '/'),
                'http.server',
                array_filter([
                    'http.method' => $request->getMethod(),
                    'http.url' => $request->url(),
                    'http.path' => '/'.ltrim($request->path(), '/'),
                    'http.request_id' => $requestId,
                    'http.user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                ], static fn (string $value): bool => $value !== ''),
                'server',
            );
        }

        $captured = $span !== null && $this->captures($request, (array) ($config['capture']['request_paths'] ?? []));

        if ($captured) {
            $this->captureRequest($span, $request);
        }

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            if ($span !== null) {
                $span->setStatus('error');
                $span->finish();
                $this->keepSummary($span);
            }

            throw $exception;
        }

        if ($span !== null) {
            if ($captured) {
                $this->captureResponse($span, $response);
            }

            $this->finishSpan($span, $request, $response);
            $this->keepSummary($span);
        }

        if ($response instanceof Response) {
            $response->headers->set('X-Trace-Id', $traceId);
            $response->headers->set('X-Request-Id', $requestId);
        }

        return $response;
    }

    private function finishSpan(\Elgiosoft\Logger\Tracing\Span $span, Request $request, mixed $response): void
    {
        $route = $request->route();
        $uri = is_object($route) && method_exists($route, 'uri') ? $route->uri() : null;

        if ($uri !== null) {
            $span->setName($request->getMethod().' /'.ltrim($uri, '/'));
            $span->setAttribute('http.route', '/'.ltrim($uri, '/'));
        }

        if (is_object($route) && method_exists($route, 'getName') && $route->getName()) {
            $span->setAttribute('http.route_name', $route->getName());
        }

        $status = $response instanceof Response ? $response->getStatusCode() : 200;
        $span->setStatusCode($status);
        $span->setAttribute('http.status_code', $status);

        if ($status >= 500) {
            $span->setStatus('error');
        } elseif ($span->status() === 'unset') {
            $span->setStatus('ok');
        }

        $span->finish();
    }

    /**
     * @param  list<string>  $patterns
     */
    private function captures(Request $request, array $patterns): bool
    {
        return $patterns !== [] && $request->is(...$patterns);
    }

    private function captureRequest(\Elgiosoft\Logger\Tracing\Span $span, Request $request): void
    {
        try {
            $span->setAttribute('http.request.headers', $this->capture->headers($request->headers->all()));

            if ($request->query() !== []) {
                $span->setAttribute('http.request.query', $request->query());
            }

            $body = $this->capture->fromString($request->getContent(), $request->headers->get('Content-Type'));

            if ($body !== null) {
                $span->setAttribute('http.request.body', $body);
            }
        } catch (Throwable $exception) {
            $this->client->reportFailure('request capture failed: '.$exception->getMessage());
        }
    }

    private function captureResponse(\Elgiosoft\Logger\Tracing\Span $span, mixed $response): void
    {
        try {
            if (! $response instanceof Response) {
                return;
            }

            $body = $this->capture->fromString($response->getContent(), $response->headers->get('Content-Type'));

            if ($body !== null) {
                $span->setAttribute('http.response.body', $body);
            }
        } catch (Throwable $exception) {
            $this->client->reportFailure('response capture failed: '.$exception->getMessage());
        }
    }

    /**
     * An unsampled request still sends its own span (no children), so every request is listed.
     */
    private function keepSummary(\Elgiosoft\Logger\Tracing\Span $span): void
    {
        $tracer = $this->client->tracer();

        $tracing = (array) ($this->client->config()['tracing'] ?? []);

        if ($tracer->isSampled() || ! (bool) ($tracing['enabled'] ?? true) || ! (bool) ($tracing['all_requests'] ?? true)) {
            return;
        }

        $span->setAttribute('sampled', false);
        $tracer->keepUnsampled($span);
    }

    private function requestId(Request $request): string
    {
        $header = (string) $request->headers->get('X-Request-Id', '');

        if ($header !== '' && strlen($header) <= 128 && preg_match('/^[A-Za-z0-9._:-]+$/', $header) === 1) {
            return $header;
        }

        return Ids::uuid();
    }

    /**
     * @return array<string, mixed>
     */
    private function httpContext(Request $request, bool $sendPii): array
    {
        $http = [
            'method' => $request->getMethod(),
            'url' => $request->url(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ];

        if ($sendPii) {
            $http['ip'] = $request->ip();
        }

        return array_filter($http, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  list<string>  $patterns
     */
    private function isIgnored(Request $request, array $patterns): bool
    {
        return $patterns !== [] && $request->is(...$patterns);
    }
}
