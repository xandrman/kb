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
 * Родитель нового спана — вершина собственного стека, а если он пуст — активный контекст (корень запроса от nginx).
 * Внутри конвейера ContextStorage не меняется: стек живёт в синглтоне, а его уборку делает владелец конвейера
 * (unwindTo), поэтому ни брошенный генератор, ни сбой агента не оставляют родителя следующему запросу или задаче.
 *
 * Атрибуты — только числа и служебные значения: ни вопроса, ни ответа, ни ПДн в трейсе нет.
 */
class Tracing
{
    private readonly TracerInterface $tracer;

    /**
     * Open spans, innermost last; $tag — what closes the span (the end event of a Neuron pair), null for our own spans.
     *
     * @var list<array{span: SpanInterface, tag: string|null}>
     */
    private array $stack = [];

    public function __construct(TracerProviderInterface $tracerProvider)
    {
        $this->tracer = $tracerProvider->getTracer('kb');
    }

    /**
     * Start a span as the child of the top of the stack, or of the active context; it is not put on the stack.
     *
     * @param  non-empty-string  $name
     * @param  array<string, bool|int|float|string>  $attributes
     * @param  SpanKind::KIND_*  $kind
     */
    public function start(string $name, array $attributes = [], int $kind = SpanKind::KIND_INTERNAL): SpanInterface
    {
        $builder = $this->tracer->spanBuilder($name)->setSpanKind($kind)->setAttributes($attributes);

        if ($this->stack !== []) {
            $builder->setParent($this->stack[array_key_last($this->stack)]['span']->storeInContext(Context::getCurrent()));
        }

        return $builder->startSpan();
    }

    /**
     * Start a span and make it the parent of the following ones until it is ended.
     *
     * @param  non-empty-string  $name
     * @param  array<string, bool|int|float|string>  $attributes
     */
    public function begin(string $name, array $attributes = [], ?string $tag = null): SpanInterface
    {
        $span = $this->start($name, $attributes);
        $this->stack[] = ['span' => $span, 'tag' => $tag];

        return $span;
    }

    /**
     * End a span begun with begin(), wherever it is in the stack.
     */
    public function end(SpanInterface $span): void
    {
        for ($index = count($this->stack) - 1; $index >= 0; $index--) {
            if ($this->stack[$index]['span'] === $span) {
                array_splice($this->stack, $index, 1);

                break;
            }
        }

        $span->end();
    }

    /**
     * The innermost open span with the tag, removed from the stack but not ended, or null.
     */
    public function takeTagged(string $tag): ?SpanInterface
    {
        for ($index = count($this->stack) - 1; $index >= 0; $index--) {
            if ($this->stack[$index]['tag'] === $tag) {
                $span = $this->stack[$index]['span'];
                array_splice($this->stack, $index, 1);

                return $span;
            }
        }

        return null;
    }

    /**
     * Fail and end the tagged spans on top of the stack, down to the first span of our own.
     */
    public function failTagged(?Throwable $exception): void
    {
        while ($this->stack !== [] && $this->stack[array_key_last($this->stack)]['tag'] !== null) {
            $span = array_pop($this->stack)['span'];

            if ($exception !== null) {
                self::fail($span, $exception);
            } else {
                $span->setStatus(StatusCode::STATUS_ERROR);
            }

            $span->end();
        }
    }

    public function depth(): int
    {
        return count($this->stack);
    }

    /**
     * Close what is left above the depth: spans the pipeline abandoned (stopped generator, broken stream).
     */
    public function unwindTo(int $depth): void
    {
        while (count($this->stack) > $depth && ($entry = array_pop($this->stack)) !== null) {
            $span = $entry['span'];
            $span->setStatus(StatusCode::STATUS_ERROR, 'abandoned');
            $span->end();
        }
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
        $span = $this->begin($name, $attributes);

        try {
            return $work($span);
        } catch (Throwable $exception) {
            self::fail($span, $exception);

            throw $exception;
        } finally {
            $this->end($span);
        }
    }

    public static function fail(SpanInterface $span, Throwable $exception): void
    {
        $span->recordException($exception);
        $span->setStatus(StatusCode::STATUS_ERROR, $exception::class);
    }
}
