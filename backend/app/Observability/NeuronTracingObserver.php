<?php

namespace App\Observability;

use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\PostProcessed;
use NeuronAI\Observability\Events\PostProcessing;
use NeuronAI\Observability\ObserverInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;

/**
 * Neuron events as OpenTelemetry spans (ADR-0022: the Inspector observer is replaced, nothing leaves the perimeter).
 *
 * Спаны не активируются: родитель задаётся явно, поэтому исключение внутри агента не оставит «висящий» контекст.
 * Процесс PHP обрабатывает один запрос или задачу за раз, поэтому открытые спаны — один стек.
 */
class NeuronTracingObserver implements ObserverInterface
{
    /**
     * Start event => end event.
     */
    private const array PAIRS = [
        'workflow-start' => 'workflow-end',
        'inference-start' => 'inference-stop',
        'rag-postprocessing' => 'rag-postprocessed',
    ];

    /**
     * @var list<array{end: string, span: SpanInterface}>
     */
    private array $open = [];

    public function __construct(private readonly Tracing $tracing) {}

    public function onEvent(string $event, object $source, mixed $data = null, ?string $branchId = null): void
    {
        if (isset(self::PAIRS[$event])) {
            $parent = $this->open === [] ? null : $this->open[array_key_last($this->open)]['span'];
            $this->open[] = ['end' => self::PAIRS[$event], 'span' => $this->tracing->start($this->name($event, $source, $data), $this->startAttributes($data), parent: $parent)];

            return;
        }

        if (in_array($event, self::PAIRS, true)) {
            $this->finish($event, $data);

            return;
        }

        if ($event === 'error') {
            $this->failAll($data);
        }
    }

    /**
     * @return non-empty-string
     */
    private function name(string $event, object $source, mixed $data): string
    {
        return match ($event) {
            'workflow-start' => 'agent '.class_basename($source),
            'inference-start' => 'llm.inference',
            default => 'rag.postprocess '.($data instanceof PostProcessing ? class_basename($data->processor) : ''),
        };
    }

    /**
     * @return array<string, int>
     */
    private function startAttributes(mixed $data): array
    {
        return $data instanceof PostProcessing ? ['kb.documents.in' => count($data->documents)] : [];
    }

    private function finish(string $event, mixed $data): void
    {
        // Закрываем самый вложенный незакрытый спан этой пары: события одной пары вложены по порядку
        for ($index = count($this->open) - 1; $index >= 0; $index--) {
            if ($this->open[$index]['end'] !== $event) {
                continue;
            }

            $span = $this->open[$index]['span'];
            if ($data instanceof PostProcessed) {
                $span->setAttribute('kb.documents.out', count($data->documents));
            }
            $span->end();
            array_splice($this->open, $index, 1);

            return;
        }
    }

    private function failAll(mixed $data): void
    {
        while ($this->open !== []) {
            $span = array_pop($this->open)['span'];
            if ($data instanceof AgentError) {
                Tracing::fail($span, $data->exception);
            } else {
                $span->setStatus(StatusCode::STATUS_ERROR);
            }

            $span->end();
        }
    }
}
