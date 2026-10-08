<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Tracing;

use Elgiosoft\Logger\Support\Ids;

/**
 * W3C trace context "traceparent" header: 00-<trace-id>-<parent-id>-<flags>.
 */
final class TraceParent
{
    /**
     * @return array{trace_id: string, parent_span_id: string, sampled: bool}|null
     */
    public static function parse(?string $header): ?array
    {
        if ($header === null) {
            return null;
        }

        if (preg_match('/^([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/', strtolower(trim($header)), $matches) !== 1) {
            return null;
        }

        [, $version, $traceId, $parentId, $flags] = $matches;

        if ($version === 'ff' || ! Ids::isTraceId($traceId) || ! Ids::isSpanId($parentId)) {
            return null;
        }

        return [
            'trace_id' => $traceId,
            'parent_span_id' => $parentId,
            'sampled' => (hexdec($flags) & 1) === 1,
        ];
    }

    public static function format(string $traceId, string $spanId, bool $sampled): string
    {
        return sprintf('00-%s-%s-%s', $traceId, $spanId, $sampled ? '01' : '00');
    }
}
