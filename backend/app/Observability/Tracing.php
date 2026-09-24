<?php

namespace App\Observability;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use Throwable;

/**
 * Spans of the query pipeline for the end-to-end trace (FR-9).
 *
 * Атрибуты — только числа и служебные значения: ни вопроса, ни ответа, ни ПДн в трейсе нет.
 */
class Tracing
{
    private readonly TracerInterface $tracer;

    public function __construct(TracerProviderInterface $tracerProvider)
    {
        $this->tracer = $tracerProvider->getTracer('kb');
    }

    /**
     * Start a span as the child of $parent, or of the active span; the caller activates and ends it.
     *
     * @param  non-empty-string  $name
     * @param  array<string, bool|int|float|string>  $attributes
     * @param  SpanKind::KIND_*  $kind
     */
    public function start(string $name, array $attributes = [], int $kind = SpanKind::KIND_INTERNAL, ?SpanInterface $parent = null): SpanInterface
    {
        $builder = $this->tracer->spanBuilder($name)->setSpanKind($kind)->setAttributes($attributes);

        if ($parent !== null) {
            $builder->setParent($parent->storeInContext(Context::getCurrent()));
        }

        return $builder->startSpan();
    }

    /**
     * Run the work inside a span; an exception marks the span as failed and goes on.
     *
     * @template T
     *
     * @param  non-empty-string  $name
     * @param  callable(SpanInterface): T  $work
     * @param  array<string, bool|int|float|string>  $attributes
     * @return T
     */
    public function span(string $name, callable $work, array $attributes = []): mixed
    {
        $span = $this->start($name, $attributes);
        $scope = $span->activate();

        try {
            return $work($span);
        } catch (Throwable $exception) {
            self::fail($span, $exception);

            throw $exception;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    public static function fail(SpanInterface $span, Throwable $exception): void
    {
        $span->recordException($exception);
        $span->setStatus(StatusCode::STATUS_ERROR, $exception::class);
    }
}
