<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Transport;

/**
 * Outcome of one POST to the collector.
 */
final class Delivery
{
    public const DELIVERED = 'delivered';

    /** Connection error, timeout, 5xx, 408 or 429: worth sending again later. */
    public const RETRYABLE = 'retryable';

    /** 413: the batch is too large and must be split. */
    public const TOO_LARGE = 'too_large';

    /** Any other 4xx (bad key, invalid payload…): sending it again will not help. */
    public const REJECTED = 'rejected';

    /**
     * @param  float|null  $retryAfter  Seconds from the Retry-After header of a 429/503, when present.
     */
    public function __construct(
        public readonly string $outcome,
        public readonly string $reason = '',
        public readonly ?int $status = null,
        public readonly ?float $retryAfter = null,
    ) {}

    public function delivered(): bool
    {
        return $this->outcome === self::DELIVERED;
    }

    public function retryable(): bool
    {
        return $this->outcome === self::RETRYABLE;
    }

    /**
     * Split an envelope's logs and spans into two halves (for a 413). Null when it holds a single item.
     *
     * @param  array<string, mixed>  $envelope
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null
     */
    public static function split(array $envelope): ?array
    {
        $logs = array_values((array) ($envelope['logs'] ?? []));
        $spans = array_values((array) ($envelope['spans'] ?? []));

        if (count($logs) + count($spans) < 2) {
            return null;
        }

        $halfLogs = intdiv(count($logs) + 1, 2);
        $halfSpans = intdiv(count($spans) + (count($logs) === 0 ? 1 : 0), 2);

        $first = $envelope;
        $second = $envelope;
        $first['logs'] = array_slice($logs, 0, $halfLogs);
        $second['logs'] = array_slice($logs, $halfLogs);
        $first['spans'] = array_slice($spans, 0, $halfSpans);
        $second['spans'] = array_slice($spans, $halfSpans);

        foreach ([&$first, &$second] as &$half) {
            foreach (['logs', 'spans'] as $key) {
                if ($half[$key] === []) {
                    unset($half[$key]);
                }
            }
        }

        return [$first, $second];
    }
}
