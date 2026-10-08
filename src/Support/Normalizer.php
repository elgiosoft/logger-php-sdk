<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Support;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * Turns arbitrary PHP values into JSON-safe data without ever throwing.
 */
final class Normalizer
{
    public function __construct(
        private readonly int $maxDepth = 6,
        private readonly int $maxItems = 200,
        private readonly int $maxStringLength = 8192,
    ) {}

    public function normalize(mixed $value, int $depth = 0): mixed
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? $value : (string) $value;
        }

        if (is_string($value)) {
            return $this->truncate($value);
        }

        if ($depth >= $this->maxDepth) {
            return is_array($value) ? '[array]' : '[object '.get_debug_type($value).']';
        }

        if (is_array($value)) {
            return $this->normalizeArray($value, $depth);
        }

        if (is_object($value)) {
            return $this->normalizeObject($value, $depth);
        }

        return '['.get_debug_type($value).']';
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    public function normalizeArray(array $value, int $depth = 0): array
    {
        $normalized = [];
        $count = 0;

        foreach ($value as $key => $item) {
            if (++$count > $this->maxItems) {
                $normalized['...'] = sprintf('%d more items', count($value) - $this->maxItems);
                break;
            }

            $normalized[$key] = $this->normalize($item, $depth + 1);
        }

        return $normalized;
    }

    private function normalizeObject(object $value, int $depth): mixed
    {
        try {
            if ($value instanceof DateTimeInterface) {
                return Time::format($value);
            }

            if ($value instanceof Throwable) {
                return [
                    'type' => $value::class,
                    'message' => $this->truncate($value->getMessage()),
                    'file' => $value->getFile(),
                    'line' => $value->getLine(),
                ];
            }

            if ($value instanceof BackedEnum) {
                return $value->value;
            }

            if ($value instanceof UnitEnum) {
                return $value->name;
            }

            if ($value instanceof JsonSerializable) {
                return $this->normalize($value->jsonSerialize(), $depth + 1);
            }

            if ($value instanceof Arrayable) {
                return $this->normalize($value->toArray(), $depth + 1);
            }

            if ($value instanceof Stringable) {
                return $this->truncate((string) $value);
            }

            $properties = get_object_vars($value);

            if ($properties !== []) {
                return ['_class' => $value::class] + $this->normalizeArray($properties, $depth + 1);
            }
        } catch (Throwable) {
            // Fall through to the class placeholder below.
        }

        return '[object '.$value::class.']';
    }

    public function truncate(string $value, ?int $length = null): string
    {
        $length ??= $this->maxStringLength;

        if (strlen($value) <= $length) {
            return $this->ensureUtf8($value);
        }

        return $this->ensureUtf8(substr($value, 0, $length)).'…';
    }

    private function ensureUtf8(string $value): string
    {
        if (preg_match('//u', $value) === 1) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }
}
