<?php

namespace App\Services\Tracing;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Throwable;

/**
 * Thin wrapper around the OpenTelemetry tracer: span helpers and W3C trace-context
 * propagation (HTTP headers, queue payloads, outgoing provider calls).
 * When tracing is disabled the provider is a no-op and every helper costs nothing.
 */
class Tracing
{
    private const INSTRUMENTATION = 'eda-notification-system';

    public function __construct(private readonly TracerProviderInterface $provider) {}

    public function tracer(): TracerInterface
    {
        return $this->provider->getTracer(self::INSTRUMENTATION);
    }

    /**
     * Runs $callback inside a new span (child of the active span or of $parent).
     * Exceptions are recorded on the span and rethrown.
     *
     * @template T
     *
     * @param  callable(SpanInterface): T  $callback
     * @param  array<string, scalar|null>  $attributes
     * @return T
     */
    public function inSpan(string $name, callable $callback, array $attributes = [], int $kind = SpanKind::KIND_INTERNAL, ?ContextInterface $parent = null): mixed
    {
        $builder = $this->tracer()->spanBuilder($name)->setSpanKind($kind)->setAttributes(array_filter($attributes, fn ($v) => $v !== null));

        if ($parent !== null) {
            $builder->setParent($parent);
        }

        $span = $builder->startSpan();
        $scope = $span->activate();

        try {
            return $callback($span);
        } catch (Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    /**
     * W3C trace-context headers (`traceparent`, `tracestate`) for the active span.
     *
     * @return array<string, string>
     */
    public function propagationHeaders(): array
    {
        $carrier = [];
        TraceContextPropagator::getInstance()->inject($carrier);

        return $carrier;
    }

    /**
     * @param  array<string, string|list<string>>  $carrier  headers or a queue payload
     */
    public function extract(array $carrier): ContextInterface
    {
        return TraceContextPropagator::getInstance()->extract($carrier);
    }

    public function currentTraceId(): ?string
    {
        $context = Span::getCurrent()->getContext();

        return $context->isValid() ? $context->getTraceId() : null;
    }

    public function hasActiveSpan(): bool
    {
        return Span::getCurrent()->getContext()->isValid();
    }

    /**
     * Export buffered spans now (end of an HTTP request / after each queued job).
     */
    public function flush(): void
    {
        if ($this->provider instanceof TracerProvider) {
            $this->provider->forceFlush();
        }
    }

    public function root(): ContextInterface
    {
        return Context::getRoot();
    }
}
