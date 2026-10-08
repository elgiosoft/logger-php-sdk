<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Serializers;

use Throwable;

/**
 * Serializes a throwable (and its "previous" chain) into the contract's exception shape.
 *
 * values[0] is the thrown exception, values[1..] its previous chain. Within each value,
 * frames[0] is the innermost frame (where the exception was thrown).
 */
final class ExceptionSerializer
{
    /**
     * @var array<string, list<string>|null>
     */
    private array $fileCache = [];

    /**
     * @param  list<string>  $inAppPaths  Absolute path prefixes considered application code.
     * @param  list<string>  $inAppExclude  Path fragments that are never application code.
     */
    public function __construct(
        private readonly array $inAppPaths,
        private readonly array $inAppExclude = ['/vendor/'],
        private readonly int $contextLines = 5,
        private readonly int $maxFrames = 100,
        private readonly int $maxContextFrames = 20,
        private readonly int $maxChain = 10,
    ) {}

    /**
     * @return array{values: list<array<string, mixed>>}
     */
    public function serialize(Throwable $exception): array
    {
        $values = [];
        $seen = [];

        for ($current = $exception; $current !== null && count($values) < $this->maxChain; $current = $current->getPrevious()) {
            $id = spl_object_id($current);

            if (isset($seen[$id])) {
                break;
            }

            $seen[$id] = true;
            $values[] = $this->serializeOne($current);
        }

        $this->fileCache = [];

        return ['values' => $values];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeOne(Throwable $exception): array
    {
        $frames = [];
        $file = $exception->getFile();
        $line = $exception->getLine();

        foreach ($this->traceWithoutErrorHandler($exception) as $entry) {
            $frames[] = $this->frame($file, $line, $entry['function'] ?? null, $entry['class'] ?? null);
            $file = $entry['file'] ?? null;
            $line = $entry['line'] ?? null;
        }

        if ($file !== null) {
            $frames[] = $this->frame($file, $line, '{main}', null);
        }

        $frames = array_slice($frames, 0, $this->maxFrames);
        $withContext = 0;

        foreach ($frames as $index => $frame) {
            if ($frame['in_app'] && $withContext < $this->maxContextFrames && isset($frame['file'], $frame['line'])) {
                $frames[$index] += $this->sourceContext($frame['file'], $frame['line']);
                $withContext++;
            }
        }

        $code = $exception->getCode();

        return [
            'type' => $exception::class,
            'value' => $exception->getMessage(),
            'code' => is_int($code) || is_string($code) ? $code : 0,
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'frames' => $frames,
        ];
    }

    /**
     * PHP warnings converted to ErrorException carry the error handler's own frames first
     * (handleError, its closure). Drop everything up to the call made from the erroring line,
     * so frame 0 is the function that actually raised the warning.
     *
     * @return list<array<string, mixed>>
     */
    private function traceWithoutErrorHandler(Throwable $exception): array
    {
        $trace = $exception->getTrace();

        if (! $exception instanceof \ErrorException) {
            return $trace;
        }

        foreach ($trace as $index => $entry) {
            if (($entry['file'] ?? null) === $exception->getFile() && ($entry['line'] ?? null) === $exception->getLine()) {
                return array_slice($trace, $index + 1);
            }
        }

        return $trace;
    }

    /**
     * @return array<string, mixed>
     */
    private function frame(?string $file, ?int $line, ?string $function, ?string $class): array
    {
        $frame = [];

        if ($file !== null) {
            $frame['file'] = $file;
            $frame['line'] = $line ?? 0;
        }

        if ($function !== null) {
            $frame['function'] = $function;
        }

        if ($class !== null) {
            $frame['class'] = $class;
        }

        $frame['in_app'] = $file !== null && $this->isInApp($file);

        return $frame;
    }

    public function isInApp(string $file): bool
    {
        $normalized = str_replace('\\', '/', $file);

        foreach ($this->inAppExclude as $fragment) {
            if ($fragment !== '' && str_contains($normalized, $fragment)) {
                return false;
            }
        }

        foreach ($this->inAppPaths as $path) {
            if ($path !== '' && str_starts_with($normalized, rtrim(str_replace('\\', '/', $path), '/').'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{pre_context?: list<string>, context_line?: string, post_context?: list<string>}
     */
    private function sourceContext(string $file, int $line): array
    {
        $lines = $this->readLines($file);

        if ($lines === null || $line < 1 || $line > count($lines)) {
            return [];
        }

        $index = $line - 1;
        $start = max(0, $index - $this->contextLines);

        return [
            'pre_context' => array_map($this->cleanLine(...), array_slice($lines, $start, $index - $start)),
            'context_line' => $this->cleanLine($lines[$index]),
            'post_context' => array_map($this->cleanLine(...), array_slice($lines, $index + 1, $this->contextLines)),
        ];
    }

    /**
     * @return list<string>|null
     */
    private function readLines(string $file): ?array
    {
        if (array_key_exists($file, $this->fileCache)) {
            return $this->fileCache[$file];
        }

        $lines = null;

        if (@is_file($file) && @is_readable($file) && (int) @filesize($file) <= 1_048_576) {
            $content = @file($file, FILE_IGNORE_NEW_LINES);
            $lines = $content === false ? null : array_values($content);
        }

        return $this->fileCache[$file] = $lines;
    }

    private function cleanLine(string $line): string
    {
        $line = rtrim($line, "\r\n");

        return strlen($line) > 300 ? substr($line, 0, 300).'…' : $line;
    }
}
