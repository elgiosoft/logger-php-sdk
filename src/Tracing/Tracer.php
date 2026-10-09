<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Tracing;

use Closure;
use Elgiosoft\Logger\Support\Ids;

/**
 * Holds the active trace (id, sampling decision) and the stack of open spans.
 *
 * Long-running processes (queue workers, sync jobs inside requests) save and restore the
 * state around each unit of work so traces never leak into each other.
 */
final class Tracer
{
    private ?string $traceId = null;

    private ?string $remoteParentSpanId = null;

    private bool $sampled = true;

    /**
     * @var list<Span>
     */
    private array $stack = [];

    private int $recordedSpans = 0;

    /**
     * @var list<array{trace_id: ?string, remote: ?string, sampled: bool, stack: list<Span>, recorded: int}>
     */
    private array $savedStates = [];

    /**
     * @param  Closure(Span): void  $onFinish  Receives every finished span of a sampled trace.
     */
    public function __construct(
        private readonly Closure $onFinish,
        private readonly float $sampleRate = 1.0,
        private readonly int $maxSpansPerTrace = 1000,
    ) {}

    /**
     * Start a new trace, continuing the one described by a W3C traceparent header when valid.
     */
    public function startTrace(?string $traceparent = null): string
    {
        $parent = TraceParent::parse($traceparent);

        if ($parent !== null) {
            return $this->continueTrace($parent['trace_id'], $parent['parent_span_id'], $parent['sampled']);
        }

        return $this->continueTrace(Ids::traceId(), null, $this->shouldSample());
    }

    public function continueTrace(string $traceId, ?string $parentSpanId, ?bool $sampled = null): string
    {
        $this->traceId = Ids::isTraceId($traceId) ? $traceId : Ids::traceId();
        $this->remoteParentSpanId = Ids::isSpanId($parentSpanId) ? $parentSpanId : null;
        $this->sampled = $sampled ?? $this->shouldSample();
        $this->stack = [];
        $this->recordedSpans = 0;

        return $this->traceId;
    }

    public function traceId(): ?string
    {
        return $this->traceId;
    }

    public function hasActiveTrace(): bool
    {
        return $this->traceId !== null;
    }

    public function isSampled(): bool
    {
        return $this->sampled;
    }

    public function currentSpan(): ?Span
    {
        return $this->stack === [] ? null : $this->stack[array_key_last($this->stack)];
    }

    public function rootSpan(): ?Span
    {
        return $this->stack[0] ?? null;
    }

    /**
     * Id of the innermost open span, or the remote parent when no local span is open.
     */
    public function currentSpanId(): ?string
    {
        return $this->currentSpan()?->spanId ?? $this->remoteParentSpanId;
    }

    /**
     * Open a span as a child of the current one and make it current. Starts a trace when needed.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function startSpan(string $name, string $op = 'function', array $attributes = [], string $kind = 'internal', ?float $startTime = null): Span
    {
        $span = $this->createSpan($name, $op, $attributes, $kind, $startTime);
        $this->stack[] = $span;

        return $span;
    }

    /**
     * Create a child span without making it current (for concurrent work like async HTTP calls).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createSpan(string $name, string $op = 'function', array $attributes = [], string $kind = 'internal', ?float $startTime = null): Span
    {
        if ($this->traceId === null) {
            $this->startTrace();
        }

        return new Span($this, (string) $this->traceId, $this->currentSpanId(), $name, $op, $kind, $attributes, $startTime);
    }

    /**
     * Record an already completed child span (e.g. a database query that reports its own duration).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function recordSpan(string $name, string $op, float $startTime, float $endTime, array $attributes = [], string $kind = 'internal', string $status = 'ok'): ?Span
    {
        if ($this->traceId === null) {
            return null;
        }

        $span = $this->createSpan($name, $op, $attributes, $kind, $startTime);
        $span->setStatus($status);
        $span->finish($endTime);

        return $span;
    }

    /**
     * Called by Span::finish().
     */
    public function finished(Span $span): void
    {
        foreach ($this->stack as $index => $open) {
            if ($open === $span) {
                array_splice($this->stack, $index, 1);
                break;
            }
        }

        if (! $this->sampled || $span->traceId !== $this->traceId || $this->recordedSpans >= $this->maxSpansPerTrace) {
            return;
        }

        $this->recordedSpans++;
        ($this->onFinish)($span);
    }

    /**
     * Record a finished span of an unsampled trace anyway (a request's summary row).
     */
    public function keepUnsampled(Span $span): void
    {
        if ($this->sampled || $span->traceId !== $this->traceId) {
            return;
        }

        ($this->onFinish)($span);
    }

    public function markError(): void
    {
        $this->currentSpan()?->setStatus('error');
        $this->rootSpan()?->setStatus('error');
    }

    /**
     * traceparent header value for an outgoing call made from the given span (or the current one).
     */
    public function traceparent(?Span $span = null): ?string
    {
        if ($this->traceId === null) {
            return null;
        }

        $spanId = $span?->spanId ?? $this->currentSpanId() ?? Ids::spanId();

        return TraceParent::format($this->traceId, $spanId, $this->sampled);
    }

    public function saveState(): void
    {
        $this->savedStates[] = [
            'trace_id' => $this->traceId,
            'remote' => $this->remoteParentSpanId,
            'sampled' => $this->sampled,
            'stack' => $this->stack,
            'recorded' => $this->recordedSpans,
        ];
    }

    public function restoreState(): void
    {
        $state = array_pop($this->savedStates);

        if ($state === null) {
            $this->reset();

            return;
        }

        $this->traceId = $state['trace_id'];
        $this->remoteParentSpanId = $state['remote'];
        $this->sampled = $state['sampled'];
        $this->stack = $state['stack'];
        $this->recordedSpans = $state['recorded'];
    }

    public function reset(): void
    {
        $this->traceId = null;
        $this->remoteParentSpanId = null;
        $this->sampled = true;
        $this->stack = [];
        $this->recordedSpans = 0;
    }

    private function shouldSample(): bool
    {
        if ($this->sampleRate >= 1.0) {
            return true;
        }

        if ($this->sampleRate <= 0.0) {
            return false;
        }

        return mt_rand() / mt_getrandmax() < $this->sampleRate;
    }
}
