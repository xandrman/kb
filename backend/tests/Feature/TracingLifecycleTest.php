<?php

namespace Tests\Feature;

use App\Observability\NeuronTracingObserver;
use App\Observability\Tracing;
use ArrayObject;
use Illuminate\Queue\Events\Looping;
use NeuronAI\Observability\Events\AgentError;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use RuntimeException;
use stdClass;
use Tests\TestCase;

class TracingLifecycleTest extends TestCase
{
    /**
     * @var ArrayObject<int, ImmutableSpan>
     */
    private ArrayObject $spans;

    private Tracing $tracing;

    private NeuronTracingObserver $observer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->spans = new ArrayObject;
        $this->tracing = new Tracing(new TracerProvider(new SimpleSpanProcessor(new InMemoryExporter($this->spans))));
        $this->observer = new NeuronTracingObserver($this->tracing);
        $this->app->instance(Tracing::class, $this->tracing);
    }

    public function test_steps_inside_an_agent_nest_under_its_spans(): void
    {
        $agent = new stdClass;

        $this->tracing->span('rag.answer', function () use ($agent): void {
            $this->observer->onEvent('workflow-start', $agent);
            $this->tracing->span('rag.vector_search', fn () => $this->tracing->start('HTTP POST kb-vllm-embedding')->end());
            $this->observer->onEvent('inference-start', $agent);
            $this->tracing->start('HTTP POST kb-vllm-generate')->end();
            $this->observer->onEvent('inference-stop', $agent);
            $this->observer->onEvent('workflow-end', $agent);
        });

        $this->assertParent('rag.answer', 'agent stdClass');
        $this->assertParent('agent stdClass', 'rag.vector_search');
        $this->assertParent('rag.vector_search', 'HTTP POST kb-vllm-embedding');
        $this->assertParent('agent stdClass', 'llm.inference');
        $this->assertParent('llm.inference', 'HTTP POST kb-vllm-generate');
        $this->assertSame(0, $this->tracing->depth());
    }

    public function test_spans_an_abandoned_generator_left_open_are_closed_by_its_owner(): void
    {
        $answering = (function () {
            $depth = $this->tracing->depth();
            $span = $this->tracing->begin('rag.answer');

            try {
                $this->observer->onEvent('workflow-start', new stdClass);
                yield 'Ищу в документах фрагменты по вопросу…';
                $this->observer->onEvent('workflow-end', new stdClass);
            } finally {
                $this->tracing->unwindTo($depth + 1);
                $this->tracing->end($span);
            }
        })();

        $answering->current();
        unset($answering);

        $this->assertSame(0, $this->tracing->depth());
        $agent = $this->spanNamed('agent stdClass');
        $this->assertSame(StatusCode::STATUS_ERROR, $agent->getStatus()->getCode());
        $this->assertSame('abandoned', $agent->getStatus()->getDescription());
        $this->assertTrue($this->spanNamed('rag.answer')->hasEnded());
        $this->tracing->start('next.request')->end();
        $this->assertFalse($this->spanNamed('next.request')->getParentContext()->isValid());
    }

    public function test_an_agent_error_fails_its_spans_and_leaves_the_owner_open(): void
    {
        $owner = $this->tracing->begin('rag.answer');
        $this->observer->onEvent('workflow-start', new stdClass);
        $this->observer->onEvent('inference-start', new stdClass);

        $this->observer->onEvent('error', new stdClass, new AgentError(new RuntimeException('vLLM недоступен')));

        $this->assertSame(1, $this->tracing->depth());
        foreach (['agent stdClass', 'llm.inference'] as $name) {
            $this->assertSame(StatusCode::STATUS_ERROR, $this->spanNamed($name)->getStatus()->getCode());
            $this->assertSame('exception', $this->spanNamed($name)->getEvents()[0]->getName());
        }
        $this->tracing->end($owner);
    }

    public function test_a_queue_worker_starts_every_job_without_an_inherited_parent(): void
    {
        $this->tracing->begin('agent GraphExtractor', tag: 'workflow-end');

        event(new Looping('redis', 'graph'));

        $this->assertSame(0, $this->tracing->depth());
    }

    private function assertParent(string $parent, string $child): void
    {
        $this->assertSame($this->spanNamed($parent)->getSpanId(), $this->spanNamed($child)->getParentSpanId(), "{$child} должен быть внутри {$parent}");
    }

    private function spanNamed(string $name): ImmutableSpan
    {
        foreach ($this->spans as $span) {
            if ($span->getName() === $name) {
                return $span;
            }
        }

        $this->fail("Нет спана {$name}");
    }
}
