<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Support;

/**
 * Identifier generators matching the ingestion contract (W3C trace context + uuid v4).
 */
final class Ids
{
    /**
     * 32 lowercase hex characters (W3C trace-id).
     */
    public static function traceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * 16 lowercase hex characters (W3C span-id).
     */
    public static function spanId(): string
    {
        return bin2hex(random_bytes(8));
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public static function isTraceId(?string $value): bool
    {
        return $value !== null && preg_match('/^[0-9a-f]{32}$/', $value) === 1 && $value !== str_repeat('0', 32);
    }

    public static function isSpanId(?string $value): bool
    {
        return $value !== null && preg_match('/^[0-9a-f]{16}$/', $value) === 1 && $value !== str_repeat('0', 16);
    }
}
