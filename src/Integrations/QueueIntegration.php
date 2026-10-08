<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Integrations;

use Elgiosoft\Logger\Client;
use Elgiosoft\Logger\Jobs\SendBatch;
use Elgiosoft\Logger\Support\Time;
use Elgiosoft\Logger\Tracing\Span;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Queue;
use Throwable;

/**
 * Propagates the trace into queued jobs and wraps each job in a "queue.job" span.
 *
 * - On dispatch: a "queue.publish" span is recorded and the trace context is stored in the
 *   job payload under "elgiosoft_trace".
 * - In the worker: the trace is continued, a consumer span is opened, and on completion the
 *   span is finished and the buffer flushed. State is saved/restored so synchronous jobs
 *   running inside a request do not clobber the request's trace.
 */
final class QueueIntegration
{
    public const PAYLOAD_KEY = 'elgiosoft_trace';

    /**
     * @var array<string, array{span: ?Span, own: bool}>
     */
    private array $running = [];

    public function __construct(private readonly Client $client) {}

    public function register(Dispatcher $events): void
    {
        Queue::createPayloadUsing(fn (string $connection, ?string $queue, array $payload): array => $this->payload($connection, $queue, $payload));

        $events->listen(JobProcessing::class, fn (JobProcessing $event) => $this->starting($event->job, $event->connectionName));
        $events->listen(JobProcessed::class, fn (JobProcessed $event) => $this->finished($event->job, null));
        $events->listen(JobFailed::class, fn (JobFailed $event) => $this->markFailed($event->job));
        $events->listen(JobExceptionOccurred::class, fn (JobExceptionOccurred $event) => $this->finished($event->job, $event->exception));
        $events->listen(Looping::class, fn () => $this->client->flush());
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function payload(string $connection, ?string $queue, array $payload): array
    {
        try {
            $tracer = $this->client->tracer();

            if (! $this->client->isRecording() || ! $tracer->hasActiveTrace() || $this->isOwnJob($payload)) {
                return [];
            }

            $parentSpanId = $tracer->currentSpanId();

            if ($tracer->currentSpan() !== null && (bool) ($this->client->config()['tracing']['queue'] ?? true)) {
                $now = Time::now();
                $publish = $tracer->recordSpan(
                    'publish '.$this->displayName($payload),
                    'queue.publish',
                    $now,
                    $now,
                    ['job.queue' => $queue, 'job.connection' => $connection],
                    'producer',
                );
                $parentSpanId = $publish?->spanId ?? $parentSpanId;
            }

            return [self::PAYLOAD_KEY => [
                'trace_id' => $tracer->traceId(),
                'span_id' => $parentSpanId,
                'sampled' => $tracer->isSampled(),
            ]];
        } catch (Throwable $exception) {
            $this->client->reportFailure('queue payload failed: '.$exception->getMessage());

            return [];
        }
    }

    private function starting(Job $job, string $connectionName): void
    {
        try {
            $payload = $job->payload();
            $key = $this->key($job);

            if ($this->isOwnJob($payload)) {
                $this->running[$key] = ['span' => null, 'own' => true];

                return;
            }

            $this->client->pushScope();
            $tracer = $this->client->tracer();
            $tracer->saveState();

            $context = $payload[self::PAYLOAD_KEY] ?? null;

            if (is_array($context) && is_string($context['trace_id'] ?? null)) {
                $tracer->continueTrace($context['trace_id'], $context['span_id'] ?? null, (bool) ($context['sampled'] ?? true));
            } else {
                $tracer->startTrace();
            }

            $span = null;

            if ((bool) ($this->client->config()['tracing']['queue'] ?? true)) {
                $span = $tracer->startSpan($this->displayName($payload), 'queue.job', [
                    'job.id' => $job->getJobId(),
                    'job.queue' => $job->getQueue(),
                    'job.connection' => $connectionName,
                    'job.attempts' => $job->attempts(),
                ], 'consumer');
            }

            $this->running[$key] = ['span' => $span, 'own' => false];
        } catch (Throwable $exception) {
            $this->client->reportFailure('queue job tracing failed: '.$exception->getMessage());
        }
    }

    private function markFailed(Job $job): void
    {
        ($this->running[$this->key($job)]['span'] ?? null)?->setStatus('error');
    }

    private function finished(Job $job, ?Throwable $exception): void
    {
        $key = $this->key($job);
        $state = $this->running[$key] ?? null;

        if ($state === null || $state['own']) {
            unset($this->running[$key]);

            return;
        }

        unset($this->running[$key]);

        try {
            if ($state['span'] !== null) {
                $state['span']->setStatus($exception === null ? 'ok' : 'error');
                $state['span']->finish();
            }

            $this->client->flush();
        } finally {
            $this->client->tracer()->restoreState();
            $this->client->popScope();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isOwnJob(array $payload): bool
    {
        $name = $payload['data']['commandName'] ?? $payload['displayName'] ?? null;

        return $name === SendBatch::class || $name === 'elgiosoft-logger:send-batch';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function displayName(array $payload): string
    {
        return (string) ($payload['displayName'] ?? $payload['job'] ?? 'job');
    }

    private function key(Job $job): string
    {
        return spl_object_id($job).':'.$job->getJobId();
    }
}
