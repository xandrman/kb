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

        yield new ProgressEvent('Формирую ответ (источников: '.count($event->documents).')…');

        return new AIInferenceEvent(
            instructions: $this->baseInstructions."\n\n<CONTEXT>\n".$this->context($event->documents).'</CONTEXT>',
            tools: $this->tools,
        );
    }

    /**
     * @param  Chunk[]  $chunks
     */
    private function context(array $chunks): string
    {
        $context = '';

        foreach (array_values($chunks) as $number => $chunk) {
            $context .= 'Фрагмент '.($number + 1).":\n".$chunk->getContent()."\n";

            // Факты графа показывают модели, как фрагмент связан с вопросом, найденным по соседству
            foreach ($chunk->metadata['graph_facts'] ?? [] as $fact) {
                $context .= "Связь: {$fact}\n";
            }

            $context .= "\n";
        }

        return $context;
    }
}
