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

        yield new ProgressEvent('Формирую ответ…');

        return new AIInferenceEvent(
            instructions: $this->baseInstructions."\n\n<CONTEXT>\n".$this->context($event->documents).'</CONTEXT>',
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
            ! empty($chunk->metadata['also_in']) ? 'тот же текст в «'.implode('», «', $chunk->metadata['also_in']).'»' : null,
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
            $context .= 'Фрагмент '.($number + 1).$this->source($chunk).":\n".$chunk->getContent()."\n";

            // Факты графа показывают модели, как фрагмент связан с вопросом, найденным по соседству
            foreach ($chunk->metadata['graph_facts'] ?? [] as $fact) {
                $context .= "Связь: {$fact}\n";
            }

            $context .= "\n";
        }

        return $context;
    }
}
