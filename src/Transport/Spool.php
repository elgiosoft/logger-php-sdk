<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Transport;

use Throwable;

/**
 * Local directory of envelopes that could not be delivered (collector down, rate limited), resent by the
 * next successful flush of any process. Files are claimed by renaming them, so concurrent PHP workers
 * never send the same batch twice; batches older than max_age are dropped, and once max_files are
 * waiting new batches are dropped instead of filling the disk.
 */
final class Spool
{
    private const CLAIM_TIMEOUT = 600;

    public function __construct(
        private readonly string $directory,
        private readonly int $maxFiles = 100,
        private readonly int $maxAgeSeconds = 86400,
    ) {}

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Store an envelope. Returns false when it could not be stored (full, unwritable).
     *
     * @param  array<string, mixed>  $envelope
     */
    public function push(array $envelope): bool
    {
        try {
            if (! is_dir($this->directory) && ! @mkdir($this->directory, 0775, true) && ! is_dir($this->directory)) {
                return false;
            }

            if (count($this->files('*.json')) >= $this->maxFiles) {
                return false;
            }

            $json = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

            if ($json === false) {
                return false;
            }

            $name = sprintf('%s/%.6F-%s', $this->directory, microtime(true), bin2hex(random_bytes(4)));

            // Written under a temporary name and renamed, so a reader never sees half a file.
            if (@file_put_contents($name.'.tmp', $json, LOCK_EX) === false) {
                return false;
            }

            return @rename($name.'.tmp', $name.'.json');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Hand at most $limit stored envelopes, oldest first, to $send. When it returns true the file is
     * removed; when it returns false the file is put back and draining stops (the collector still fails).
     *
     * @param  callable(array<string, mixed>): bool  $send
     */
    public function drain(callable $send, int $limit = 5): int
    {
        $sent = 0;

        try {
            if (! is_dir($this->directory)) {
                return 0;
            }

            $this->releaseStaleClaims();

            foreach ($this->files('*.json') as $file) {
                if ($sent >= $limit) {
                    break;
                }

                if (time() - (int) @filemtime($file) > $this->maxAgeSeconds) {
                    @unlink($file);

                    continue;
                }

                $claimed = $file.'.'.getmypid().'.sending';

                if (! @rename($file, $claimed)) {
                    continue;
                }

                $envelope = json_decode((string) @file_get_contents($claimed), true);

                if (! is_array($envelope)) {
                    @unlink($claimed);

                    continue;
                }

                if (! $send($envelope)) {
                    @rename($claimed, $file);

                    break;
                }

                @unlink($claimed);
                $sent++;
            }
        } catch (Throwable) {
            // Never let the spool break a flush.
        }

        return $sent;
    }

    public function count(): int
    {
        return count($this->files('*.json'));
    }

    /**
     * Files claimed by a process that died before sending them go back to the spool.
     */
    private function releaseStaleClaims(): void
    {
        foreach ($this->files('*.sending') as $claimed) {
            if (time() - (int) @filemtime($claimed) > self::CLAIM_TIMEOUT) {
                @rename($claimed, (string) preg_replace('/\.\d+\.sending$/', '', $claimed));
            }
        }
    }

    /**
     * @return list<string>
     */
    private function files(string $pattern): array
    {
        $files = glob($this->directory.'/'.$pattern) ?: [];
        sort($files);

        return $files;
    }
}
