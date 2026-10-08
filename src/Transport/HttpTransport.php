<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Transport;

use Closure;
use Elgiosoft\Logger\Client;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * POSTs envelopes to {endpoint}/api/v1/ingest.
 */
final class HttpTransport implements Transport
{
    /**
     * @param  array<string, mixed>  $config
     * @param  Closure(string): void  $onFailure
     */
    public function __construct(
        private readonly array $config,
        private readonly Closure $onFailure,
    ) {}

    public function send(array $envelope): void
    {
        $this->post($envelope);
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    public function post(array $envelope): bool
    {
        try {
            $json = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

            if ($json === false) {
                ($this->onFailure)('could not encode envelope: '.json_last_error_msg());

                return false;
            }

            $timeout = max(0.1, (float) ($this->config['timeout'] ?? 2));
            $headers = [
                'User-Agent' => Client::SDK_NAME.'/'.Client::VERSION,
                'Accept' => 'application/json',
            ];

            if ((bool) ($this->config['compress'] ?? true) && function_exists('gzencode')) {
                $json = (string) gzencode($json, 6);
                $headers['Content-Encoding'] = 'gzip';
            }

            $response = Http::withToken((string) $this->config['key'])
                ->withHeaders($headers)
                ->timeout((int) ceil($timeout))
                ->connectTimeout((int) ceil(min($timeout, 1.0)))
                ->withBody($json, 'application/json')
                ->post($this->url());

            if ($response->successful()) {
                return true;
            }

            ($this->onFailure)(sprintf('collector responded %d: %s', $response->status(), mb_substr($response->body(), 0, 200)));
        } catch (Throwable $exception) {
            ($this->onFailure)('could not reach collector: '.$exception->getMessage());
        }

        return false;
    }

    public function url(): string
    {
        return rtrim((string) $this->config['endpoint'], '/').'/api/v1/ingest';
    }
}
