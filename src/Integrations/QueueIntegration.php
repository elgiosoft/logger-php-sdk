<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Integrations;

use Elgiosoft\Logger\Client;
use Elgiosoft\Logger\Jobs\SendBatch;
use Elgiosoft\Logger\Support\Ids;
use Elgiosoft\Logger\Support\Time;
use Elgiosoft\Logger\Tracing\Span;
use Elgiosoft\Logger\Tracing\TraceParent;
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
 * - A job with a public "traceId" property (a trace id or a full traceparent) runs in that trace
 *   instead of the dispatcher's, e.g. to keep a payment's trace across jobs dispatched later.
 * - When a job throws, the worker reports the exception only after the job's trace is restored,
 *   so the trace is remembered on the exception and the error log still links to it.
 * - Each job starts with an empty breadcrumb buffer (the dispatcher's is restored afterwards).
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

            if (! $this->client->isRecording() || $this->isOwnJob($payload)) {
                return [];
            }

            $requested = $this->requestedTrace($payload);

            if ($requested !== null && $requested['trace_id'] !== $tracer->traceId()) {
                return [self::PAYLOAD_KEY => $requested];
            }

            if (! $tracer->hasActiveTrace()) {
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
            $this->client->clearBreadcrumbs();

            if ($this->client->recordsBreadcrumbs('queue')) {
                $this->client->addBreadcrumb('queue', 'Processing '.$this->displayName($payload), 'info', [
                    'queue' => $job->getQueue(),
                    'connection' => $connectionName,
                    'attempts' => $job->attempts(),
                ]);
            }

            $tracer = $this->client->tracer();
            $tracer->saveState();

            $context = $payload[self::PAYLOAD_KEY] ?? null;

            if (is_array($context) && is_string($context['trace_id'] ?? null)) {
                $tracer->continueTrace($context['trace_id'], $context['span_id'] ?? null, isset($context['sampled']) ? (bool) $context['sampled'] : null);
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
            if ($exception !== null) {
                $this->client->rememberExceptionOrigin($exception, $state['span']?->spanId);
            }

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
     * The trace a job asks to run in through a public "traceId" property: a 32-hex trace id
     * (sampling decided by the worker) or a full traceparent.
     *
     * @param  array<string, mixed>  $payload
     * @return array{trace_id: string, span_id: ?string, sampled?: bool}|null
     */
    private function requestedTrace(array $payload): ?array
    {
        $command = $payload['data']['command'] ?? null;
        $value = is_object($command) ? (get_object_vars($command)['traceId'] ?? null) : null;

        if (! is_string($value) || $value === '') {
            return null;
        }

        $parent = TraceParent::parse($value);

        if ($parent !== null) {
            return ['trace_id' => $parent['trace_id'], 'span_id' => $parent['parent_span_id'], 'sampled' => $parent['sampled']];
        }

        $traceId = strtolower($value);

        return Ids::isTraceId($traceId) ? ['trace_id' => $traceId, 'span_id' => null] : null;
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
