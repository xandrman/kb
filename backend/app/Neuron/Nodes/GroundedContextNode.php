<?php

namespace App\Neuron\Nodes;

use App\Neuron\Events\ProgressEvent;
use Generator;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\RAG\Document as Chunk;
use NeuronAI\RAG\Events\DocumentsProcessedEvent;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;

/**
 * Replaces Neuron's InstructionsNode: puts the retrieved chunks into the instructions, or refuses without calling the model (FR-5).
 */
class GroundedContextNode extends Node
{
    public const string REFUSAL = 'Нет данных в базе знаний.';

    /**
     * Progress step right before the model starts the answer; the MCP tool ends its TTFT span on it (FR-9).
     */
    public const string ANSWERING = 'Формирую ответ…';

    /**
     * Agent state key of the fragment texts the answer was built from.
     */
    public const string CONTEXT_STATE_KEY = 'context_fragments';

    /**
     * Agent state key of where those fragments come from: chunk, document, access level, pages (SRS 6.4 audit log).
     */
    public const string SOURCES_STATE_KEY = 'context_sources';

    /**
     * Markup of the context block; the output guardrail looks for it in answers (DetectContextLeak).
     */
    public const string CONTEXT_OPEN = '<CONTEXT>';

    public const string CONTEXT_CLOSE = '</CONTEXT>';

    public const string FRAGMENT_LABEL = 'Фрагмент';

    public const string FACT_LABEL = 'Связь';

    public const string ALSO_IN_LABEL = 'тот же текст в';

    /**
     * @param  ToolInterface[]  $tools
     */
    public function __construct(
        private readonly string $baseInstructions,
        private readonly array $tools,
    ) {}

    /**
     * @return Generator<int, ProgressEvent, mixed, AIInferenceEvent|StopEvent>
     */
    public function __invoke(DocumentsProcessedEvent $event, AgentState $state): Generator
    {
        // FR-5: ответ из весов модели без опоры на документы запрещён — без источников модель не вызывается вовсе
        if ($event->documents === []) {
            $state->getChatHistory()->addMessage(new AssistantMessage(self::REFUSAL));

            return new StopEvent;
        }

        yield new ProgressEvent(self::ANSWERING);

        // Выходной guardrail сверяет с ними ответ: значения из фрагментов уже прошли маскирование при загрузке
        $state->set(self::CONTEXT_STATE_KEY, array_map(fn (Chunk $chunk): string => $chunk->getContent(), array_values($event->documents)));
        $state->set(self::SOURCES_STATE_KEY, array_map(fn (Chunk $chunk): array => [
            'chunk_id' => (string) $chunk->getId(),
            'document_id' => $chunk->metadata['document_id'] ?? null,
            'access_level' => $chunk->metadata['access_level'] ?? null,
            'page_numbers' => $chunk->metadata['page_numbers'] ?? null,
        ], array_values($event->documents)));

        return new AIInferenceEvent(
            instructions: $this->baseInstructions."\n\n".self::CONTEXT_OPEN."\n".$this->context($event->documents).self::CONTEXT_CLOSE,
            tools: $this->tools,
        );
    }

    /**
     * The document of the fragment: the model is usually in its name, not in the fragment text.
     */
    private function source(Chunk $chunk): string
    {
        $parts = array_filter([
            isset($chunk->metadata['document_name']) ? "документ «{$chunk->metadata['document_name']}»" : null,
            ! empty($chunk->metadata['also_in']) ? self::ALSO_IN_LABEL.' «'.implode('», «', $chunk->metadata['also_in']).'»' : null,
            $chunk->metadata['document_type'] ?? null,
            ! empty($chunk->metadata['sku']) ? "SKU {$chunk->metadata['sku']}" : null,
            ! empty($chunk->metadata['page_numbers']) ? 'стр. '.implode(', ', $chunk->metadata['page_numbers']) : null,
        ]);

        return $parts === [] ? '' : ' ('.implode(', ', $parts).')';
    }

    /**
     * @param  Chunk[]  $chunks
     */
    private function context(array $chunks): string
    {
        $context = '';

        foreach (array_values($chunks) as $number => $chunk) {
            $context .= self::FRAGMENT_LABEL.' '.($number + 1).$this->source($chunk).":\n".$chunk->getContent()."\n";

            // Факты графа показывают модели, как фрагмент связан с вопросом, найденным по соседству
            foreach ($chunk->metadata['graph_facts'] ?? [] as $fact) {
                $context .= self::FACT_LABEL.": {$fact}\n";
            }

            $context .= "\n";
        }

        return $context;
    }
}
