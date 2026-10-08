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
     * Build a log event and add it to the buffer. Returns the event id, or null when not captured.
     *
     * @param  array<array-key, mixed>  $context
     */
    public function capture(string $level, string $message, array $context = [], ?string $channel = null, ?DateTimeInterface $time = null, ?string $event = null): ?string
    {
        if (! $this->isRecording()) {
            return null;
        }

        try {
            $log = $this->buildLog($level, $message, $context, $channel, $time, $event);
        } catch (Throwable $exception) {
            $this->reportFailure('could not build log event: '.$exception->getMessage());

            return null;
        }

        $this->logs[] = $log;
        $this->afterCapture();

        return $log['id'];
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
        $extraTags = isset($context['tags']) && is_array($context['tags']) ? $context['tags'] : [];
        unset($context['tags']);

        $log = [
            'id' => Ids::uuid(),
            'timestamp' => Time::format($time ?? Time::now()),
            'level' => $level,
            'message' => $this->normalizer->truncate($message, 32768),
            'event' => $event,
            'channel' => $channel,
            'trace_id' => $this->tracer->traceId(),
            'span_id' => $this->tracer->currentSpan()?->spanId,
            'request_id' => $requestId,
            'transaction_id' => $transactionId,
            'user' => $this->resolveUser(),
            'http' => $this->requestContext['http'] ?? null,
            'context' => $this->clean(array_merge($this->scope->contexts, $context)),
            'tags' => $this->buildTags($extraTags),
            'fingerprint' => $fingerprint,
        ];

        if ($exception !== null) {
            $log['exception'] = $this->exceptionSerializer->serialize($exception);

            if (Level::isAtLeast($level, 'error')) {
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
