<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Transport;

use Closure;
use DateTimeImmutable;
use Elgiosoft\Logger\Client;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * POSTs envelopes to {endpoint}/api/v1/ingest.
 *
 * send() (deferred and sync modes) retries transient failures — connection errors, timeouts, 5xx, 408
 * and 429 — with exponential backoff and jitter inside a small time budget (deferred: 2 retries within
 * 3 s; sync: none), never sooner than a 429's Retry-After. A 413 splits the batch in half and sends each
 * half. Other 4xx answers are reported and dropped. A batch that still fails is handed to the fallback:
 * the queue (only when ELGIOSOFT_LOGGER_QUEUE_CONNECTION is set) or else the local spool, which the next
 * successful send replays.
 */
final class HttpTransport implements Transport
{
    private const MAX_SPLIT_DEPTH = 10;

    /**
     * @var (Closure(float): void)|null
     */
    private static ?Closure $sleeper = null;

    /**
     * @param  array<string, mixed>  $config
     * @param  Closure(string): void  $onFailure
     * @param  Spool|null  $spool  Where undeliverable batches wait for the next successful send.
     * @param  Transport|null  $fallbackQueue  Queue transport used instead of the spool when configured.
     */
    public function __construct(
        private readonly array $config,
        private readonly Closure $onFailure,
        private readonly ?Spool $spool = null,
        private readonly ?Transport $fallbackQueue = null,
    ) {}

    /**
     * Replace the sleep between retries (tests). Null restores usleep().
     *
     * @param  (Closure(float): void)|null  $sleeper  Receives the delay in seconds.
     */
    public static function sleepUsing(?Closure $sleeper): void
    {
        self::$sleeper = $sleeper;
    }

    public function send(array $envelope): void
    {
        [$retries, $budget] = $this->retryPolicy();

        if ($this->deliver($envelope, $retries, microtime(true) + $budget) && $this->spool !== null) {
            $this->replaySpool();
        }
    }

    /**
     * One delivery attempt, reporting a failure. Used by the test command.
     *
     * @param  array<string, mixed>  $envelope
     */
    public function post(array $envelope): bool
    {
        $delivery = $this->attempt($envelope);

        if (! $delivery->delivered()) {
            ($this->onFailure)($delivery->reason);
        }

        return $delivery->delivered();
    }

    /**
     * One POST, classified. Never throws and never reports: the caller decides what a failure means.
     *
     * @param  array<string, mixed>  $envelope
     */
    public function attempt(array $envelope): Delivery
    {
        try {
            $json = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

            if ($json === false) {
                return new Delivery(Delivery::REJECTED, 'could not encode envelope: '.json_last_error_msg());
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

            return $this->classify($response);
        } catch (Throwable $exception) {
            return new Delivery(Delivery::RETRYABLE, 'could not reach collector: '.$exception->getMessage());
        }
    }

    public function url(): string
    {
        return rtrim((string) $this->config['endpoint'], '/').'/api/v1/ingest';
    }

    /**
     * Delay before retry number $attempt (0-based): exponential from $base seconds, with "equal jitter"
     * (half fixed, half random) so many processes failing together don't retry in lockstep.
     */
    public static function backoff(int $attempt, float $base = 0.25, float $cap = 300.0): float
    {
        $delay = min($cap, $base * (2 ** max(0, $attempt)));

        return $delay / 2 + (mt_rand() / mt_getrandmax()) * ($delay / 2);
    }

    /**
     * Seconds to wait from a Retry-After header (delta-seconds or an HTTP date).
     */
    public static function parseRetryAfter(?string $header): ?float
    {
        $header = trim((string) $header);

        if ($header === '') {
            return null;
        }

        if (is_numeric($header)) {
            return max(0.0, (float) $header);
        }

        $date = DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $header);

        return $date === false ? null : max(0.0, (float) ($date->getTimestamp() - time()));
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function deliver(array $envelope, int $retries, float $deadline, int $depth = 0): bool
    {
        for ($attempt = 0; ; $attempt++) {
            $delivery = $this->attempt($envelope);

            if ($delivery->delivered()) {
                return true;
            }

            if ($delivery->outcome === Delivery::TOO_LARGE) {
                $halves = $depth < self::MAX_SPLIT_DEPTH ? Delivery::split($envelope) : null;

                if ($halves === null) {
                    ($this->onFailure)('dropping batch: '.$delivery->reason);

                    return false;
                }

                $first = $this->deliver($halves[0], $retries, $deadline, $depth + 1);

                return $this->deliver($halves[1], $retries, $deadline, $depth + 1) && $first;
            }

            if ($delivery->outcome === Delivery::REJECTED) {
                ($this->onFailure)('dropping batch: '.$delivery->reason);

                return false;
            }

            $delay = $delivery->retryAfter ?? self::backoff($attempt);

            if ($attempt >= $retries || microtime(true) + $delay > $deadline) {
                $this->fallback($envelope, $delivery);

                return false;
            }

            $this->sleep($delay);
        }
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function fallback(array $envelope, Delivery $delivery): void
    {
        $summary = sprintf('%d logs, %d spans', count((array) ($envelope['logs'] ?? [])), count((array) ($envelope['spans'] ?? [])));

        if ($this->fallbackQueue !== null) {
            ($this->onFailure)($delivery->reason.'; batch ('.$summary.') queued for retry');
            $this->fallbackQueue->send($envelope);

            return;
        }

        if ($this->spool !== null && $this->spool->push($envelope)) {
            ($this->onFailure)($delivery->reason.'; batch ('.$summary.') spooled for retry');

            return;
        }

        ($this->onFailure)($delivery->reason.'; dropping batch ('.$summary.')');
    }

    /**
     * Resend a few spooled batches after a successful delivery, while the collector is reachable.
     */
    private function replaySpool(): void
    {
        $limit = max(0, (int) ($this->config['spool']['replay_batches'] ?? 5));

        if ($limit === 0) {
            return;
        }

        $this->spool?->drain(function (array $envelope): bool {
            $delivery = $this->attempt($envelope);

            if ($delivery->outcome === Delivery::TOO_LARGE && ($halves = Delivery::split($envelope)) !== null) {
                $this->spool?->push($halves[0]);
                $this->spool?->push($halves[1]);

                return true;
            }

            if ($delivery->outcome === Delivery::REJECTED || $delivery->outcome === Delivery::TOO_LARGE) {
                ($this->onFailure)('dropping spooled batch: '.$delivery->reason);

                return true;
            }

            return $delivery->delivered();
        }, $limit);
    }

    private function classify(Response $response): Delivery
    {
        $status = $response->status();

        if ($response->successful()) {
            return new Delivery(Delivery::DELIVERED, status: $status);
        }

        $reason = sprintf('collector responded %d: %s', $status, mb_substr($response->body(), 0, 200));

        if ($status === 413) {
            return new Delivery(Delivery::TOO_LARGE, $reason, $status);
        }

        if ($status === 408 || $status === 429 || $status >= 500) {
            return new Delivery(Delivery::RETRYABLE, $reason, $status, self::parseRetryAfter($response->header('Retry-After')));
        }

        return new Delivery(Delivery::REJECTED, $reason, $status);
    }

    /**
     * @return array{0: int, 1: float} Retries and time budget in seconds for the configured mode.
     */
    private function retryPolicy(): array
    {
        $sync = ($this->config['transport'] ?? 'deferred') === 'sync';
        $retry = (array) ($this->config['retry'] ?? []);
        $retries = $retry['max_retries'] ?? null;
        $budget = $retry['budget'] ?? null;

        return [
            max(0, $retries === null || $retries === '' ? ($sync ? 0 : 2) : (int) $retries),
            max(0.0, $budget === null || $budget === '' ? ($sync ? 0.5 : 3.0) : (float) $budget),
        ];
    }

    private function sleep(float $seconds): void
    {
        if (self::$sleeper !== null) {
            (self::$sleeper)($seconds);

            return;
        }

        usleep((int) round($seconds * 1_000_000));
    }
}
