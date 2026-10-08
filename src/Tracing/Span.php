<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Tracing;

use Elgiosoft\Logger\Support\Ids;
use Elgiosoft\Logger\Support\Time;

/**
 * A unit of work inside a trace. Call finish() when it is done; finishing twice is a no-op.
 */
final class Span
{
    public readonly string $spanId;

    public readonly float $startTime;

    private ?float $endTime = null;

    private string $status = 'unset';

    private ?int $statusCode = null;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        private readonly ?Tracer $tracer,
        public readonly string $traceId,
        public readonly ?string $parentSpanId,
        private string $name,
        private string $op = 'function',
        private string $kind = 'internal',
        private array $attributes = [],
        ?float $startTime = null,
        ?string $spanId = null,
    ) {
        $this->spanId = $spanId ?? Ids::spanId();
        $this->startTime = $startTime ?? Time::now();
    }

    public function name(): string
    {
        return $this->name;
    }

    public function op(): string
    {
        return $this->op;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function setAttribute(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function setAttributes(array $attributes): self
    {
        $this->attributes = array_merge($this->attributes, $attributes);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /**
     * @param  'ok'|'error'|'unset'  $status
     */
    public function setStatus(string $status): self
    {
        $this->status = in_array($status, ['ok', 'error', 'unset'], true) ? $status : 'unset';

        return $this;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function setStatusCode(int $statusCode): self
    {
        $this->statusCode = $statusCode;

        return $this;
    }

    public function isFinished(): bool
    {
        return $this->endTime !== null;
    }

    public function finish(?float $endTime = null): void
    {
        if ($this->endTime !== null) {
            return;
        }

        $this->endTime = max($this->startTime, $endTime ?? Time::now());
        $this->tracer?->finished($this);
    }

    public function durationMs(): float
    {
        return round((($this->endTime ?? Time::now()) - $this->startTime) * 1000, 3);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $span = [
            'trace_id' => $this->traceId,
            'span_id' => $this->spanId,
            'parent_span_id' => $this->parentSpanId,
            'name' => mb_substr($this->name, 0, 1000),
            'op' => $this->op,
            'kind' => $this->kind,
            'start_time' => Time::format($this->startTime),
            'end_time' => Time::format($this->endTime ?? Time::now()),
            'duration_ms' => $this->durationMs(),
            'status' => $this->status,
            'attributes' => $this->attributes,
        ];

        if ($this->statusCode !== null) {
            $span['status_code'] = $this->statusCode;
        }

        return $span;
    }
}
