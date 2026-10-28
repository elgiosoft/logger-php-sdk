<?php

declare(strict_types=1);

namespace Elgiosoft\Logger;

use Closure;
use DateTimeInterface;
use Elgiosoft\Logger\Serializers\ExceptionSerializer;
use Elgiosoft\Logger\Support\Ids;
use Elgiosoft\Logger\Support\Level;
use Elgiosoft\Logger\Support\Normalizer;
use Elgiosoft\Logger\Support\Redactor;
use Elgiosoft\Logger\Support\Time;
use Elgiosoft\Logger\Tracing\Span;
use Elgiosoft\Logger\Tracing\Tracer;
use Elgiosoft\Logger\Transport\Transport;
use Throwable;
use WeakMap;

/**
 * Buffers log events and spans for the current unit of work and ships them to the collector.
 *
 * Nothing in here may throw into the host application: every failure ends up in the
 * fallback reporter and the data is dropped.
 */
final class Client
{
    public const SDK_NAME = 'elgiosoft/logger-php';

    public const VERSION = '1.0.0';

    private const MAX_LOGS_PER_ENVELOPE = 1000;

    private const MAX_SPANS_PER_ENVELOPE = 2000;

    /**
     * @var list<array<string, mixed>>
     */
    private array $logs = [];

    /**
     * @var list<array<string, mixed>>
     */
    private array $spans = [];

    private Scope $scope;

    /**
     * @var list<Scope>
     */
    private array $scopeStack = [];

    /**
     * @var array{request_id?: string, http?: array<string, mixed>}
     */
    private array $requestContext = [];

    private int $suppressed = 0;

    private bool $flushing = false;

    private readonly Tracer $tracer;

    /**
     * Where each exception was thrown, for exceptions reported after their trace ended
     * (Laravel's queue worker reports a job's exception after the job's events have fired).
     *
     * @var WeakMap<Throwable, array{trace_id: string, span_id: ?string, contexts: array<string, array<array-key, mixed>>, breadcrumbs: list<array<string, mixed>>}>
     */
    private WeakMap $exceptionOrigins;

    /**
     * Exceptions already captured, with the id of their log (null when sampled away) and where they were
     * captured from ("handler" for the exception-handler hook, "log" for the channel / public API), so an
     * exception reported by Laravel's handler AND logged through the "elgiosoft" channel is sent once.
     *
     * @var WeakMap<Throwable, array{id: ?string, source: string}>
     */
    private WeakMap $capturedExceptions;

    /**
     * @param  array<string, mixed>  $config  The "elgiosoft-logger" config array.
     * @param  (Closure(): (array<string, string>|null))|null  $userResolver
     * @param  (Closure(string): void)|null  $failureReporter
     */
    public function __construct(
        private array $config,
        private Transport $transport,
        private readonly ExceptionSerializer $exceptionSerializer,
        private readonly Normalizer $normalizer,
        private readonly Redactor $redactor,
        private ?Closure $userResolver = null,
        private ?Closure $failureReporter = null,
    ) {
        $this->scope = new Scope;
        $this->exceptionOrigins = new WeakMap;
        $this->capturedExceptions = new WeakMap;
        $this->tracer = new Tracer(
            fn (Span $span) => $this->addSpan($span),
            (bool) ($config['tracing']['enabled'] ?? true) ? (float) ($config['tracing']['sample_rate'] ?? 1.0) : 0.0,
            (int) ($config['tracing']['max_spans'] ?? 1000),
        );

        foreach ((array) ($config['tags'] ?? []) as $key => $value) {
            $this->setTag((string) $key, $value);
        }
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? true)
            && ! empty($this->config['key'])
            && ! empty($this->config['endpoint']);
    }

    /**
     * Whether new data should currently be captured (enabled and not inside the SDK's own work).
     */
    public function isRecording(): bool
    {
        return $this->suppressed === 0 && $this->isEnabled();
    }

    public function isSuppressed(): bool
    {
        return $this->suppressed > 0;
    }

    /**
     * Run a callback without capturing anything it logs, queries or calls.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withoutRecording(callable $callback): mixed
    {
        $this->suppressed++;

        try {
            return $callback();
        } finally {
            $this->suppressed--;
        }
    }

    public function tracer(): Tracer
    {
        return $this->tracer;
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config;
    }

    public function setTransport(Transport $transport): void
    {
        $this->transport = $transport;
    }

    public function setFailureReporter(?Closure $failureReporter): void
    {
        $this->failureReporter = $failureReporter;
    }

    // ---------------------------------------------------------------- scope

    public function scope(): Scope
    {
        return $this->scope;
    }

    /**
     * @param  array{id?: int|string, email?: string, username?: string, ip_address?: string}|null  $user
     */
    public function setUser(?array $user): void
    {
        if ($user === null) {
            $this->scope->user = null;

            return;
        }

        $clean = [];

        foreach (['id', 'email', 'username', 'ip_address'] as $key) {
            if (isset($user[$key]) && is_scalar($user[$key]) && $user[$key] !== '') {
                $clean[$key] = (string) $user[$key];
            }
        }

        $this->scope->user = $clean === [] ? null : $clean;
    }

    public function setTag(string $key, mixed $value): void
    {
        if ($value === null) {
            unset($this->scope->tags[$key]);

            return;
        }

        $this->scope->tags[$key] = $this->tagValue($value);
    }

    /**
     * @param  array<string, mixed>  $tags
     */
    public function setTags(array $tags): void
    {
        foreach ($tags as $key => $value) {
            $this->setTag((string) $key, $value);
        }
    }

    /**
     * @param  array<array-key, mixed>|null  $context
     */
    public function setContext(string $key, ?array $context): void
    {
        if ($context === null) {
            unset($this->scope->contexts[$key]);

            return;
        }

        $this->scope->contexts[$key] = $context;
    }

    public function pushScope(): void
    {
        $this->scopeStack[] = $this->scope;
        $this->scope = clone $this->scope;
    }

    public function popScope(): void
    {
        $this->scope = array_pop($this->scopeStack) ?? new Scope;
    }

    /**
     * Remember the active trace, span and contexts for an exception, so that reporting it later,
     * after that trace has ended, still links the log to the trace it was thrown in.
     */
    public function rememberExceptionOrigin(Throwable $exception, ?string $spanId = null): void
    {
        $traceId = $this->tracer->traceId();

        if ($traceId === null || isset($this->exceptionOrigins[$exception])) {
            return;
        }

        $this->exceptionOrigins[$exception] = [
            'trace_id' => $traceId,
            'span_id' => $spanId ?? $this->tracer->currentSpanId(),
            'contexts' => $this->scope->contexts,
            'breadcrumbs' => $this->scope->breadcrumbs,
        ];
    }

    // ---------------------------------------------------------------- breadcrumbs

    /**
     * Record a breadcrumb (recent activity attached to the next error). Data is normalized and redacted,
     * the message scrubbed; the oldest breadcrumb is dropped once breadcrumbs.max is reached.
     *
     * @param  array<array-key, mixed>  $data
     */
    public function addBreadcrumb(string $category, string $message, string $level = 'info', array $data = []): void
    {
        $max = $this->maxBreadcrumbs();

        if ($max === 0 || ! $this->isRecording()) {
            return;
        }

        try {
            $breadcrumb = [
                'timestamp' => Time::format(Time::now()),
                'category' => $category,
                'message' => $this->scrub($this->normalizer->truncate($message, 1024)),
                'level' => Level::normalize($level),
            ];

            if ($data !== []) {
                $breadcrumb['data'] = $this->clean($data);
            }

            $this->scope->breadcrumbs[] = $breadcrumb;

            if (count($this->scope->breadcrumbs) > $max) {
                $this->scope->breadcrumbs = array_slice($this->scope->breadcrumbs, -$max);
            }
        } catch (Throwable $exception) {
            $this->reportFailure('could not record breadcrumb: '.$exception->getMessage());
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function breadcrumbs(): array
    {
        return $this->scope->breadcrumbs;
    }

    /**
     * Start a new unit of work (request, job, command) with no breadcrumbs.
     */
    public function clearBreadcrumbs(): void
    {
        $this->scope->breadcrumbs = [];
    }

    /**
     * Whether breadcrumbs are kept for a source: logs, db_queries, http_client, cache, queue or console.
     */
    public function recordsBreadcrumbs(string $source): bool
    {
        return $this->maxBreadcrumbs() > 0
            && $this->isRecording()
            && (bool) ($this->config['breadcrumbs'][$source] ?? true);
    }

    private function maxBreadcrumbs(): int
    {
        return max(0, (int) ($this->config['breadcrumbs']['max'] ?? 30));
    }

    /**
     * @param  array{request_id?: string, http?: array<string, mixed>}  $context
     */
    public function setRequestContext(array $context): void
    {
        $this->requestContext = $context;
    }

    /**
     * @return array{request_id?: string, http?: array<string, mixed>}
     */
    public function requestContext(): array
    {
        return $this->requestContext;
    }

    // ---------------------------------------------------------------- capture

    /**
     * Capture a log from the public API, honouring the configured minimum level.
     *
     * @param  array<array-key, mixed>  $context
     */
    public function log(string $level, string $message, array $context = [], ?string $event = null): ?string
    {
        if ($this->recordsBreadcrumbs('logs')) {
            $this->addBreadcrumb('log', $message, $level, $event !== null ? ['event' => $event] : []);
        }

        if (! Level::isAtLeast($level, $this->config['level'] ?? 'debug')) {
            return null;
        }

        return $this->capture($level, $message, $context, null, null, $event);
    }

    /**
     * @param  array<array-key, mixed>  $context
     */
    public function event(string $event, string $message, array $context = [], string $level = 'info'): ?string
    {
        return $this->log($level, $message, $context, $event);
    }

    /**
     * @param  array<array-key, mixed>  $context
     */
    public function captureException(Throwable $exception, array $context = [], string $level = 'error'): ?string
    {
        $context['exception'] = $exception;

        return $this->capture($level, $exception->getMessage() !== '' ? $exception->getMessage() : $exception::class, $context);
    }

    /**
     * Capture an exception reported through Laravel's exception handler (the handler already applied
     * dontReport / shouldReport). Skipped when the same exception object was already captured; when the
     * "elgiosoft" channel later receives the handler's log record for it, that record is merged into
     * this event instead of creating a second one.
     *
     * @param  array<array-key, mixed>  $context
     */
    public function captureReportedException(Throwable $exception, array $context = [], string $level = 'error'): ?string
    {
        if (! $this->isRecording() || isset($this->capturedExceptions[$exception])) {
            return null;
        }

        $context['exception'] = $exception;

        return $this->record($level, $exception->getMessage() !== '' ? $exception->getMessage() : $exception::class, $context, null, null, null, 'handler');
    }

    /**
     * Build a log event and add it to the buffer. Returns the event id, or null when not captured.
     *
     * @param  array<array-key, mixed>  $context
     */
    public function capture(string $level, string $message, array $context = [], ?string $channel = null, ?DateTimeInterface $time = null, ?string $event = null): ?string
    {
        return $this->record($level, $message, $context, $channel, $time, $event, 'log');
    }

    public function addSpan(Span $span): void
    {
        if (! $this->isRecording()) {
            return;
        }

        try {
            $data = $span->toArray();
            $data['attributes'] = $this->clean($data['attributes']);

            if ($data['attributes'] === []) {
                unset($data['attributes']);
            }

            $this->spans[] = $data;
        } catch (Throwable $exception) {
            $this->reportFailure('could not record span: '.$exception->getMessage());

            return;
        }

        $this->afterCapture();
    }

    /**
     * Add attributes to a span that already finished but is still in the buffer (e.g. query results that
     * only exist after Laravel's QueryExecuted event). Silently skipped once the span was sent.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function amendSpan(string $spanId, array $attributes): void
    {
        try {
            for ($i = count($this->spans) - 1; $i >= 0; $i--) {
                if (($this->spans[$i]['span_id'] ?? null) === $spanId) {
                    $this->spans[$i]['attributes'] = array_merge($this->spans[$i]['attributes'] ?? [], $this->clean($attributes));

                    return;
                }
            }
        } catch (Throwable $exception) {
            $this->reportFailure('could not amend span: '.$exception->getMessage());
        }
    }

    public function redactor(): Redactor
    {
        return $this->redactor;
    }

    /**
     * Open a span as a child of the current span.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function startSpan(string $name, string $op = 'function', array $attributes = [], string $kind = 'internal'): Span
    {
        return $this->tracer->startSpan($name, $op, $attributes, $kind);
    }

    /**
     * Run a callback inside a span, marking the span as errored when the callback throws.
     *
     * @template T
     *
     * @param  callable(Span): T  $callback
     * @param  array<string, mixed>  $attributes
     * @return T
     */
    public function trace(string $name, callable $callback, string $op = 'function', array $attributes = []): mixed
    {
        $span = $this->startSpan($name, $op, $attributes);

        try {
            $result = $callback($span);

            if ($span->status() === 'unset') {
                $span->setStatus('ok');
            }

            return $result;
        } catch (Throwable $exception) {
            $span->setStatus('error');

            throw $exception;
        } finally {
            $span->finish();
        }
    }

    public function traceId(): ?string
    {
        return $this->tracer->traceId();
    }

    /**
     * W3C traceparent header value for the current span, to pass the trace to another service
     * by hand (raw Guzzle/cURL, Node, a message broker…). Starts a trace when none is active.
     * Returns null when the SDK is disabled.
     */
    public function traceparent(): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        if (! $this->tracer->hasActiveTrace()) {
            $this->tracer->startTrace();
        }

        return $this->tracer->traceparent();
    }

    /**
     * Headers to merge into any outgoing request: ['traceparent' => '00-…-…-01'] (or [] when disabled).
     *
     * @return array<string, string>
     */
    public function traceHeaders(): array
    {
        $traceparent = $this->traceparent();

        return $traceparent === null ? [] : ['traceparent' => $traceparent];
    }

    /**
     * Guzzle middleware for raw Guzzle clients: `$stack->push(ElgioLogger::guzzleMiddleware())`.
     *
     * @return callable(callable): callable
     */
    public function guzzleMiddleware(): callable
    {
        return GuzzleMiddleware::create();
    }

    /**
     * Join the trace described by an incoming traceparent (queue consumers, broker messages, CLI
     * entry points). HTTP requests do this automatically through the TraceRequests middleware.
     * An invalid or empty value starts a fresh trace. Returns the trace id now in use.
     */
    public function continueTrace(?string $traceparent): string
    {
        return $this->tracer->startTrace($traceparent);
    }

    // ---------------------------------------------------------------- buffer

    /**
     * @return list<array<string, mixed>>
     */
    public function pendingLogs(): array
    {
        return $this->logs;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pendingSpans(): array
    {
        return $this->spans;
    }

    /**
     * Empty the buffer and return its content as contract envelopes (chunked to the API limits).
     *
     * @return list<array<string, mixed>>
     */
    public function drainEnvelopes(): array
    {
        $logs = $this->logs;
        $spans = $this->spans;
        $this->logs = [];
        $this->spans = [];

        $logChunks = array_chunk($logs, self::MAX_LOGS_PER_ENVELOPE);
        $spanChunks = array_chunk($spans, self::MAX_SPANS_PER_ENVELOPE);
        $envelopes = [];

        for ($index = 0, $count = max(count($logChunks), count($spanChunks)); $index < $count; $index++) {
            $envelopes[] = $this->envelope($logChunks[$index] ?? [], $spanChunks[$index] ?? []);
        }

        return $envelopes;
    }

    /**
     * Send everything buffered. Never throws.
     */
    public function flush(): void
    {
        if ($this->flushing || ($this->logs === [] && $this->spans === [])) {
            return;
        }

        $this->flushing = true;

        try {
            $envelopes = $this->drainEnvelopes();

            if (! $this->isEnabled()) {
                return;
            }

            foreach ($envelopes as $envelope) {
                $this->withoutRecording(fn () => $this->transport->send($envelope));
            }
        } catch (Throwable $exception) {
            $this->reportFailure('flush failed: '.$exception->getMessage());
        } finally {
            $this->flushing = false;
        }
    }

    /**
     * Drop buffered data and per-request state (used between requests in long-running processes).
     */
    public function resetRequestState(): void
    {
        $this->requestContext = [];
        $this->scope->breadcrumbs = [];
        $this->tracer->reset();
    }

    public function reportFailure(string $message): void
    {
        $line = '[elgiosoft-logger] '.$message;

        $this->withoutRecording(function () use ($line): void {
            try {
                if ($this->failureReporter !== null) {
                    ($this->failureReporter)($line);
                } else {
                    error_log($line);
                }
            } catch (Throwable) {
                error_log($line);
            }
        });
    }

    // ---------------------------------------------------------------- internals

    /**
     * @param  list<array<string, mixed>>  $logs
     * @param  list<array<string, mixed>>  $spans
     * @return array<string, mixed>
     */
    private function envelope(array $logs, array $spans): array
    {
        return array_filter([
            'sdk' => ['name' => self::SDK_NAME, 'version' => self::VERSION],
            'service' => $this->config['service'] ?? null,
            'environment' => $this->config['environment'] ?? null,
            'release' => $this->config['release'] ?? null,
            'server_name' => $this->config['server_name'] ?? null,
            'logs' => $logs,
            'spans' => $spans,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<array-key, mixed>  $context
     */
    private function record(string $level, string $message, array $context, ?string $channel, ?DateTimeInterface $time, ?string $event, string $source): ?string
    {
        if (! $this->isRecording()) {
            return null;
        }

        $exception = isset($context['exception']) && $context['exception'] instanceof Throwable ? $context['exception'] : null;
        $previous = $exception !== null ? ($this->capturedExceptions[$exception] ?? null) : null;

        if ($previous !== null && $previous['source'] === 'handler' && $source === 'log') {
            return $this->mergeIntoCaptured($previous['id'], $level, $context, $channel);
        }

        if (! $this->passesSampling($level)) {
            if ($exception !== null) {
                $this->capturedExceptions[$exception] = ['id' => null, 'source' => $source];
            }

            return null;
        }

        try {
            $log = $this->buildLog($level, $message, $context, $channel, $time, $event);
        } catch (Throwable $failure) {
            $this->reportFailure('could not build log event: '.$failure->getMessage());

            return null;
        }

        if ($exception !== null) {
            $this->capturedExceptions[$exception] = ['id' => $log['id'], 'source' => $source];
        }

        $this->logs[] = $log;
        $this->afterCapture();

        return $log['id'];
    }

    /**
     * Fold a second capture of an exception (the handler's log record arriving through the channel) into
     * the event the exception-handler hook already buffered: its level, channel and context win.
     *
     * @param  array<array-key, mixed>  $context
     */
    private function mergeIntoCaptured(?string $id, string $level, array $context, ?string $channel): ?string
    {
        if ($id === null) {
            return null;
        }

        try {
            for ($i = count($this->logs) - 1; $i >= 0; $i--) {
                if ($this->logs[$i]['id'] !== $id) {
                    continue;
                }

                unset($context['exception']);
                $log = $this->logs[$i];
                $log['level'] = Level::normalize($level);

                foreach (['event', 'transaction_id'] as $key) {
                    $value = $this->pull($context, $key);

                    if ($value !== null) {
                        $log[$key] = $value;
                    }
                }

                $log['fingerprint'] = $this->pullFingerprint($context) ?? ($log['fingerprint'] ?? null);
                $extraTags = isset($context['tags']) && is_array($context['tags']) ? $context['tags'] : [];
                unset($context['tags']);
                $log['tags'] = array_merge($log['tags'] ?? [], $this->buildTags($extraTags));
                $log['channel'] = $channel ?? ($log['channel'] ?? null);
                $log['context'] = array_merge($log['context'] ?? [], $this->clean($context));

                $this->logs[$i] = array_filter($log, static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');

                break;
            }
        } catch (Throwable $exception) {
            $this->reportFailure('could not merge log event: '.$exception->getMessage());
        }

        return $id;
    }

    /**
     * logs_sample_rate applies below "error", errors_sample_rate from "error" up; each is independent of
     * tracing.sample_rate.
     */
    private function passesSampling(string $level): bool
    {
        $rate = Level::isAtLeast($level, 'error')
            ? (float) ($this->config['errors_sample_rate'] ?? 1.0)
            : (float) ($this->config['logs_sample_rate'] ?? 1.0);

        if ($rate >= 1.0) {
            return true;
        }

        if ($rate <= 0.0) {
            return false;
        }

        return mt_rand() / mt_getrandmax() < $rate;
    }

    private function scrub(string $text): string
    {
        return (bool) ($this->config['scrub_messages'] ?? true) ? $this->redactor->scrubText($text) : $text;
    }

    private function afterCapture(): void
    {
        $transport = $this->config['transport'] ?? 'queue';
        $maxBuffer = max(1, (int) ($this->config['max_buffer'] ?? 200));

        if ($transport === 'sync' || count($this->logs) + count($this->spans) >= $maxBuffer) {
            $this->flush();
        }
    }

    /**
     * @param  array<array-key, mixed>  $context
     * @return array<string, mixed>
     */
    private function buildLog(string $level, string $message, array $context, ?string $channel, ?DateTimeInterface $time, ?string $event): array
    {
        $level = Level::normalize($level);
        $exception = null;

        if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
            $exception = $context['exception'];
            unset($context['exception']);
        }

        $event ??= $this->pull($context, 'event');
        $transactionId = $this->pull($context, 'transaction_id');
        $requestId = $this->pull($context, 'request_id') ?? ($this->requestContext['request_id'] ?? null);
        $fingerprint = $this->pullFingerprint($context);
        $origin = $exception !== null ? ($this->exceptionOrigins[$exception] ?? null) : null;
        $extraTags = isset($context['tags']) && is_array($context['tags']) ? $context['tags'] : [];
        unset($context['tags']);
        $breadcrumbs = $origin['breadcrumbs'] ?? $this->scope->breadcrumbs;

        if ($breadcrumbs !== [] && Level::isAtLeast($level, 'error') && ! array_key_exists('breadcrumbs', $context)) {
            $context['breadcrumbs'] = $breadcrumbs;
        }

        $log = [
            'id' => Ids::uuid(),
            'timestamp' => Time::format($time ?? Time::now()),
            'level' => $level,
            'message' => $this->scrub($this->normalizer->truncate($message, 32768)),
            'event' => $event,
            'channel' => $channel,
            'trace_id' => $origin['trace_id'] ?? $this->tracer->traceId(),
            'span_id' => $origin !== null ? $origin['span_id'] : $this->tracer->currentSpan()?->spanId,
            'request_id' => $requestId,
            'transaction_id' => $transactionId,
            'user' => $this->resolveUser(),
            'http' => $this->requestContext['http'] ?? null,
            'context' => $this->clean(array_merge($origin['contexts'] ?? [], $this->scope->contexts, $context)),
            'tags' => $this->buildTags($extraTags),
            'fingerprint' => $fingerprint,
        ];

        if ($exception !== null) {
            $log['exception'] = $this->exceptionSerializer->serialize($exception);

            foreach ($log['exception']['values'] as $index => $value) {
                if (is_string($value['value'] ?? null)) {
                    $log['exception']['values'][$index]['value'] = $this->scrub($value['value']);
                }
            }

            if (Level::isAtLeast($level, 'error') && ($origin === null || $origin['trace_id'] === $this->tracer->traceId())) {
                $this->tracer->markError();
            }
        }

        return array_filter($log, static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
    }

    /**
     * @param  array<array-key, mixed>  $context
     */
    private function pull(array &$context, string $key): ?string
    {
        if (! array_key_exists($key, $context) || ! (is_string($context[$key]) || is_int($context[$key]))) {
            return null;
        }

        $value = (string) $context[$key];
        unset($context[$key]);

        return $value === '' ? null : mb_substr($value, 0, 191);
    }

    /**
     * @param  array<array-key, mixed>  $context
     * @return list<string>|null
     */
    private function pullFingerprint(array &$context): ?array
    {
        if (! isset($context['fingerprint']) || ! is_array($context['fingerprint'])) {
            return null;
        }

        $fingerprint = array_values(array_map('strval', array_filter($context['fingerprint'], 'is_scalar')));
        unset($context['fingerprint']);

        return $fingerprint === [] ? null : $fingerprint;
    }

    /**
     * @param  array<array-key, mixed>  $extra
     * @return array<string, string>
     */
    private function buildTags(array $extra): array
    {
        $tags = $this->scope->tags;

        foreach ($extra as $key => $value) {
            if (is_string($key) && (is_scalar($value) || $value instanceof \Stringable)) {
                $tags[$key] = $this->tagValue($value);
            }
        }

        return $tags;
    }

    private function tagValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return mb_substr(is_scalar($value) || $value instanceof \Stringable ? (string) $value : json_encode($value), 0, 200);
    }

    /**
     * @return array<string, string>|null
     */
    private function resolveUser(): ?array
    {
        $user = $this->scope->user;

        if ($user === null && $this->userResolver !== null) {
            try {
                $user = ($this->userResolver)();
            } catch (Throwable) {
                $user = null;
            }
        }

        return $user === [] ? null : $user;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function clean(array $data): array
    {
        return $this->redactor->redact($this->normalizer->normalizeArray($data));
    }
}
