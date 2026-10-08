<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Support;

/**
 * Recursively replaces values of sensitive keys with "[Filtered]".
 *
 * Keys are compared after lower-casing and stripping every non alphanumeric
 * character, so "Card-Number", "card_number" and "cardNumber" all match "card_number".
 */
final class Redactor
{
    public const FILTERED = '[Filtered]';

    /**
     * @var array<string, true>
     */
    private array $keys = [];

    /**
     * @param  list<string>  $keys
     */
    public function __construct(array $keys)
    {
        foreach ($keys as $key) {
            $this->keys[self::canonical((string) $key)] = true;
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $data[$key] = self::FILTERED;

                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }

    public function isSensitive(string $key): bool
    {
        return isset($this->keys[self::canonical($key)]);
    }

    private static function canonical(string $key): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($key));
    }
}
