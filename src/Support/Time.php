<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class Time
{
    /**
     * Current unix time in seconds with microsecond precision.
     */
    public static function now(): float
    {
        return microtime(true);
    }

    /**
     * Format as RFC 3339 UTC with microseconds, e.g. 2026-10-08T10:00:00.123456Z.
     */
    public static function format(float|DateTimeInterface $time): string
    {
        if (! $time instanceof DateTimeInterface) {
            $time = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $time)) ?: new DateTimeImmutable;
        }

        return DateTimeImmutable::createFromInterface($time)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');
    }
}
