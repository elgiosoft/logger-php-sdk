<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Monolog;

use Elgiosoft\Logger\Client;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Throwable;

/**
 * Monolog 2 version of {@see ElgiosoftHandler} (Laravel 9), where records are arrays.
 */
final class LegacyElgiosoftHandler extends AbstractProcessingHandler
{
    /**
     * @param  int|string  $level
     */
    public function __construct(
        private readonly Client $client,
        $level = Logger::DEBUG,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function isHandling(array $record): bool
    {
        return $this->client->isRecording() && parent::isHandling($record);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected function write(array $record): void
    {
        try {
            $context = (array) ($record['context'] ?? []);
            $extra = (array) ($record['extra'] ?? []);

            if ($extra !== []) {
                $context['extra'] = array_merge(
                    isset($context['extra']) && is_array($context['extra']) ? $context['extra'] : [],
                    $extra,
                );
            }

            $this->client->capture(
                strtolower((string) ($record['level_name'] ?? 'info')),
                (string) ($record['message'] ?? ''),
                $context,
                $record['channel'] ?? null,
                $record['datetime'] ?? null,
            );
        } catch (Throwable $exception) {
            $this->client->reportFailure('handler failed: '.$exception->getMessage());
        }
    }

    public function close(): void
    {
        $this->client->flush();
    }
}
