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

    /**
     * Records below the channel level are accepted too while log breadcrumbs are on, so Laravel still
     * fires MessageLogged for them (it skips the event when no handler wants the level); handle()
     * then lets them through without capturing them.
     */
    public function isHandling(LogRecord $record): bool
    {
        return $this->client->isRecording() && ($this->client->recordsBreadcrumbs('logs') || parent::isHandling($record));
    }

    public function handle(LogRecord $record): bool
    {
        if (! parent::isHandling($record)) {
            return false;
        }

        return parent::handle($record);
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
