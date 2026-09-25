<?php

namespace App\Observability;

use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\PostProcessed;
use NeuronAI\Observability\Events\PostProcessing;
use NeuronAI\Observability\ObserverInterface;

/**
 * Neuron events as OpenTelemetry spans (ADR-0022: the Inspector observer is replaced, nothing leaves the perimeter).
 *
 * Своего состояния нет: спан кладётся в стек Tracing с пометкой «чем закрывается» и снимается по парному событию.
 * Брошенные спаны снимает владелец конвейера (Tracing::unwindTo), поэтому они не переживают запрос или задачу.
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

    public function __construct(private readonly Tracing $tracing) {}

    public function onEvent(string $event, object $source, mixed $data = null, ?string $branchId = null): void
    {
        if (isset(self::PAIRS[$event])) {
            $this->tracing->begin($this->name($event, $source, $data), $this->startAttributes($data), tag: self::PAIRS[$event]);

            return;
        }

        if (in_array($event, self::PAIRS, true)) {
            $span = $this->tracing->takeTagged($event);

            if ($span !== null && $data instanceof PostProcessed) {
                $span->setAttribute('kb.documents.out', count($data->documents));
            }

            $span?->end();

            return;
        }

        // Исключение вылетело из узла: закрываем спаны Neuron с его причиной, спаны владельца конвейера не трогаем
        if ($event === 'error') {
            $this->tracing->failTagged($data instanceof AgentError ? $data->exception : null);
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
}
