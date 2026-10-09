<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Support;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * Turns HTTP bodies and headers into span attributes: JSON and form bodies become arrays (so the
 * redactor masks sensitive keys at any depth), XML/text keeps its text with sensitive values masked,
 * binary content is only described, and anything over the size limit is cut.
 */
final class BodyCapture
{
    /**
     * Headers never kept, whatever the redact list says.
     *
     * @var list<string>
     */
    private const DROPPED_HEADERS = ['cookie', 'set-cookie', 'authorization', 'proxy-authorization'];

    public function __construct(
        private readonly Redactor $redactor,
        private readonly int $maxBytes = 16384,
    ) {}

    /**
     * Read at most the size limit from a PSR-7 message without consuming it for the app.
     *
     * @return array<string, mixed>|string|null
     */
    public function fromMessage(MessageInterface $message): array|string|null
    {
        $stream = $message->getBody();

        if (! $stream->isSeekable() || ! $stream->isReadable()) {
            return $stream->getSize() === 0 ? null : '[stream body not captured]';
        }

        $raw = $this->peek($stream);

        return $this->fromString($raw, $message->getHeaderLine('Content-Type'), $stream->getSize());
    }

    /**
     * @return array<string, mixed>|string|null
     */
    public function fromString(string|false|null $raw, ?string $contentType = null, ?int $size = null): array|string|null
    {
        if ($raw === null || $raw === false || $raw === '') {
            return null;
        }

        $size ??= strlen($raw);
        $type = strtolower((string) $contentType);

        if ($this->isBinary($type, $raw)) {
            return sprintf('[binary body, %d bytes]', $size);
        }

        if ($size > $this->maxBytes) {
            return $this->maskText(mb_strcut($raw, 0, $this->maxBytes, 'UTF-8')).sprintf('… [cut, %d bytes in total]', $size);
        }

        if (str_contains($type, 'json') || ($type === '' && in_array(ltrim($raw)[0] ?? '', ['{', '['], true))) {
            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        if (str_contains($type, 'application/x-www-form-urlencoded')) {
            parse_str($raw, $fields);

            return $fields;
        }

        return $this->maskText($raw);
    }

    /**
     * Headers as name => value, without credentials and cookies.
     *
     * @param  array<string, list<string>|string>  $headers
     * @return array<string, string>
     */
    public function headers(array $headers): array
    {
        $kept = [];

        foreach ($headers as $name => $values) {
            $name = strtolower((string) $name);

            if (in_array($name, self::DROPPED_HEADERS, true) || $this->redactor->isSensitive($name)) {
                continue;
            }

            $kept[$name] = mb_substr(implode(', ', (array) $values), 0, 512);
        }

        ksort($kept);

        return $kept;
    }

    private function peek(StreamInterface $stream): string
    {
        try {
            $position = $stream->tell();
            $stream->rewind();
            $raw = $stream->read($this->maxBytes + 1);
            $stream->seek($position);

            return $raw;
        } catch (Throwable) {
            return '';
        }
    }

    private function isBinary(string $type, string $raw): bool
    {
        foreach (['image/', 'audio/', 'video/', 'application/pdf', 'application/zip', 'application/octet-stream', 'multipart/'] as $binary) {
            if (str_starts_with($type, $binary)) {
                return true;
            }
        }

        // A read cut at the size limit can end inside a character: only judge whole characters.
        return ! mb_check_encoding(mb_strcut($raw, 0, strlen($raw), 'UTF-8'), 'UTF-8');
    }

    /**
     * Mask sensitive values in text bodies: key=value, "key": "value" and <key>value</key>.
     */
    private function maskText(string $text): string
    {
        $mask = fn (string $key, string $value): string => $this->redactor->isSensitive($key) ? Redactor::FILTERED : $value;

        $text = (string) preg_replace_callback('/(["\']?)([A-Za-z0-9_.\-]+)\1(\s*[:=]\s*)(["\']?)([^"\'&\s,}<]*)\4/', fn (array $m): string => $m[1].$m[2].$m[1].$m[3].$m[4].$mask($m[2], $m[5]).$m[4], $text);

        return (string) preg_replace_callback('/<([A-Za-z0-9_:\-]+)(\s[^>]*)?>([^<]*)<\/\1>/', fn (array $m): string => '<'.$m[1].($m[2] ?? '').'>'.$mask($m[1], $m[3]).'</'.$m[1].'>', $text);
    }
}
