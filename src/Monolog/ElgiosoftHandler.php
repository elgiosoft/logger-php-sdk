<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Monolog;

use Elgiosoft\Logger\Client;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * Monolog handler that turns records into collector log events.
 */
final class ElgiosoftHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly Client $client,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    public function isHandling(LogRecord $record): bool
    {
        return $this->client->isRecording() && parent::isHandling($record);
    }

    protected function write(LogRecord $record): void
    {
        try {
            $context = $record->context;

            if ($record->extra !== []) {
                $context['extra'] = array_merge(
                    isset($context['extra']) && is_array($context['extra']) ? $context['extra'] : [],
                    $record->extra,
                );
            }

            $this->client->capture(
                $record->level->toPsrLogLevel(),
                $record->message,
                $context,
                $record->channel,
                $record->datetime,
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
