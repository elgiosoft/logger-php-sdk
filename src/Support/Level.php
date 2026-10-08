<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Support;

/**
 * PSR-3 level names and their Monolog severities.
 */
final class Level
{
    /**
     * @var array<string, int>
     */
    public const SEVERITIES = [
        'debug' => 100,
        'info' => 200,
        'notice' => 250,
        'warning' => 300,
        'error' => 400,
        'critical' => 500,
        'alert' => 550,
        'emergency' => 600,
    ];

    /**
     * @var array<string, string>
     */
    private const ALIASES = [
        'warn' => 'warning',
        'err' => 'error',
        'fatal' => 'critical',
        'crit' => 'critical',
        'emerg' => 'emergency',
        'trace' => 'debug',
        'information' => 'info',
    ];

    /**
     * Normalize any level spelling to a PSR-3 level name (unknown → info).
     */
    public static function normalize(mixed $level): string
    {
        if (is_int($level)) {
            $name = 'debug';
            foreach (self::SEVERITIES as $candidate => $severity) {
                if ($level >= $severity) {
                    $name = $candidate;
                }
            }

            return $name;
        }

        $level = strtolower(trim((string) $level));

        if (isset(self::SEVERITIES[$level])) {
            return $level;
        }

        return self::ALIASES[$level] ?? 'info';
    }

    public static function severity(mixed $level): int
    {
        return self::SEVERITIES[self::normalize($level)];
    }

    public static function isAtLeast(mixed $level, mixed $threshold): bool
    {
        return self::severity($level) >= self::severity($threshold);
    }
}
