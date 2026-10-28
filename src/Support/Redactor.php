<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Support;

/**
 * Recursively replaces values of sensitive keys with "[Filtered]", and masks secrets in free text.
 *
 * Keys are compared after lower-casing and stripping every non alphanumeric
 * character, so "Card-Number", "card_number" and "cardNumber" all match "card_number".
 *
 * Free text (log messages, exception values, breadcrumbs, captured text bodies) is scrubbed with the
 * patterns below, all kept here so every part of the SDK masks the same things:
 *  - `key=value`, `key: value`, `"key": "value"` and `<key>value</key>` for sensitive keys,
 *  - `Bearer …` / `Basic …` credentials,
 *  - card numbers (13–19 digits starting with 2–6, spaces or dashes allowed, Luhn-checked),
 *  - e-mail addresses, unless PII may be sent.
 */
final class Redactor
{
    public const FILTERED = '[Filtered]';

    private const XML_PATTERN = '/<([A-Za-z0-9_:\-]+)(\s[^>]*)?>([^<]*)<\/\1>/';

    private const CREDENTIALS_PATTERN = '/\b(Bearer|Basic)\s+[A-Za-z0-9\-._~+\/]{6,}=*/i';

    private const CARD_PATTERN = '/(?<![\d.])[2-6]\d(?:[ \-]?\d){11,17}(?![\d.])/';

    private const EMAIL_PATTERN = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,}/';

    /**
     * @var array<string, true>
     */
    private array $keys = [];

    /**
     * Matches `key=value` / `key: value` pairs whose key is one of the sensitive keys (null when none).
     */
    private ?string $keyValuePattern = null;

    /**
     * @param  list<string>  $keys
     * @param  bool  $scrubEmails  Mask e-mail addresses in free text (when PII must not be sent).
     */
    public function __construct(array $keys, private readonly bool $scrubEmails = false)
    {
        foreach ($keys as $key) {
            $this->keys[self::canonical((string) $key)] = true;
        }

        // Only sensitive keys are matched, so a pair's value never swallows the next pair ("failed: pin=1").
        // Separators are optional between the characters of a key: "api_key" matches apiKey and API-KEY.
        $alternatives = array_map(
            static fn (string $key): string => implode('[_\-.]?', array_map(static fn (string $char): string => preg_quote($char, '/'), str_split($key))),
            array_filter(array_keys($this->keys), static fn (string $key): bool => $key !== ''),
        );

        if ($alternatives !== []) {
            usort($alternatives, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
            $this->keyValuePattern = '/(["\']?)(?<![A-Za-z0-9_])('.implode('|', $alternatives).')(?![A-Za-z0-9_])\1(\s*[:=]\s*)'
                .'(?:"([^"]*)"|\'([^\']*)\'|((?:(?:Bearer|Basic)\s+)?[^"\'&\s,;}<]*))/i';
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

    /**
     * Mask every secret pattern in a free-text value (message, exception value, breadcrumb).
     */
    public function scrubText(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $text = $this->maskKeyValues($text);
        $text = (string) preg_replace(self::CREDENTIALS_PATTERN, '$1 '.self::FILTERED, $text);
        $text = (string) preg_replace_callback(
            self::CARD_PATTERN,
            static fn (array $match): string => self::passesLuhn((string) preg_replace('/\D/', '', $match[0])) ? self::FILTERED : $match[0],
            $text,
        );

        if ($this->scrubEmails) {
            $text = (string) preg_replace(self::EMAIL_PATTERN, self::FILTERED, $text);
        }

        return $text;
    }

    /**
     * Mask values of sensitive keys in text: key=value, key: value, "key": "value" and <key>value</key>.
     */
    public function maskKeyValues(string $text): string
    {
        $text = $this->keyValuePattern === null ? $text : (string) preg_replace_callback($this->keyValuePattern, function (array $m): string {
            // SQL placeholders (`pin = ?`, `pin = :pin`, `pin = $1`) hold no value.
            if ($m[6] !== null && preg_match('/^(\?|:\w+|\$\d+)$/', $m[6]) === 1) {
                return (string) $m[0];
            }

            $quote = match (true) {
                $m[4] !== null => '"',
                $m[5] !== null => "'",
                default => '',
            };

            return $m[1].$m[2].$m[1].$m[3].$quote.self::FILTERED.$quote;
        }, $text, -1, $count, PREG_UNMATCHED_AS_NULL);

        return (string) preg_replace_callback(
            self::XML_PATTERN,
            fn (array $m): string => '<'.$m[1].($m[2] ?? '').'>'.($this->isSensitive($m[1]) ? self::FILTERED : $m[3]).'</'.$m[1].'>',
            $text,
        );
    }

    public static function passesLuhn(string $digits): bool
    {
        $length = strlen($digits);

        if ($length < 13 || $length > 19 || ! ctype_digit($digits)) {
            return false;
        }

        $sum = 0;

        for ($i = 0; $i < $length; $i++) {
            $digit = (int) $digits[$length - 1 - $i];

            if ($i % 2 === 1) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
        }

        return $sum % 10 === 0;
    }

    private static function canonical(string $key): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($key));
    }
}
