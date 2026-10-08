<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Integrations;

use Elgiosoft\Logger\Client;
use Elgiosoft\Logger\Tracing\Span;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Injects the W3C traceparent header into outgoing Laravel HTTP client calls and records
 * an "http.client" span for each of them.
 */
final class HttpClientIntegration
{
    public function __construct(private readonly Client $client) {}

    public function register(Factory $factory): void
    {
        if (! method_exists($factory, 'globalMiddleware')) {
            return;
        }

        $factory->globalMiddleware(fn (callable $handler): callable => $this->middleware($handler));
    }

    public function middleware(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            $tracer = $this->client->tracer();

            if (! $this->client->isRecording() || ! $tracer->hasActiveTrace()) {
                return $handler($request, $options);
            }

            $span = null;

            try {
                if ((bool) ($this->client->config()['tracing']['http_client'] ?? true) && $tracer->currentSpan() !== null) {
                    $uri = $request->getUri();
                    $url = $uri->getScheme().'://'.$uri->getHost().($uri->getPort() ? ':'.$uri->getPort() : '').$uri->getPath();

                    $span = $tracer->createSpan(
                        $request->getMethod().' '.$uri->getHost().$uri->getPath(),
                        'http.client',
                        [
                            'http.method' => $request->getMethod(),
                            'http.url' => $url,
                            'server.address' => $uri->getHost(),
                        ],
                        'client',
                    );
                }

                $traceparent = $tracer->traceparent($span);

                if ($traceparent !== null) {
                    $request = $request->withHeader('traceparent', $traceparent);
                }
            } catch (Throwable $exception) {
                $this->client->reportFailure('http client tracing failed: '.$exception->getMessage());
            }

            return $handler($request, $options)->then(
                function (ResponseInterface $response) use ($span): ResponseInterface {
                    $this->finish($span, $response->getStatusCode());

                    return $response;
                },
                function (mixed $reason) use ($span): PromiseInterface {
                    if ($span !== null) {
                        $span->setAttribute('error.message', $reason instanceof Throwable ? $reason->getMessage() : 'request failed');
                    }

                    $this->finish($span, null);

                    return Create::rejectionFor($reason);
                },
            );
        };
    }

    private function finish(?Span $span, ?int $status): void
    {
        if ($span === null) {
            return;
        }

        if ($status !== null) {
            $span->setStatusCode($status);
            $span->setAttribute('http.status_code', $status);
        }

        $span->setStatus($status !== null && $status < 500 ? 'ok' : 'error');
        $span->finish();
    }
}
