<?php

namespace App\Neuron\PostProcessor;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Document as Chunk;
use NeuronAI\RAG\PostProcessor\LocalAIRerankerPostProcessor;

/**
 * Reorders retrieved chunks with kb-vllm-reranker and keeps the best ones (ADR-0011); its score replaces the vector one.
 *
 * Реранкер видит фрагмент вместе с документом и SKU: модель изделия обычно есть в названии документа, а не в тексте
 * фрагмента, и без этого вопрос о «MAG301RF» не отличает её раздел от такого же раздела другой модели.
 * Замер на стенде: нужный фрагмент поднимался с 0,19 на 0,99; вопросы без модели и без ответа в документе не страдают.
 */
class RerankerPostProcessor extends LocalAIRerankerPostProcessor
{
    public function process(Message $question, array $documents): array
    {
        // Без кандидатов в сервис не ходим: реранкеру нечего упорядочивать
        if ($documents === []) {
            return [];
        }

        $documents = array_values($documents);
        $withSource = array_map(fn (Chunk $chunk): Chunk => new Chunk($this->source($chunk).$chunk->getContent()), $documents);

        // Реранкер оценивает текст с документом, а дальше идут исходные фрагменты: подпись документа LLM получает отдельно
        return array_map(function (Chunk $ranked) use ($withSource, $documents): Chunk {
            $original = $documents[array_search($ranked, $withSource, true)];
            $original->setScore($ranked->getScore());

            return $original;
        }, parent::process($question, $withSource));
    }

    private function source(Chunk $chunk): string
    {
        $parts = array_filter([
            isset($chunk->metadata['document_name']) ? "Документ: {$chunk->metadata['document_name']}" : null,
            ! empty($chunk->metadata['sku']) ? "SKU: {$chunk->metadata['sku']}" : null,
        ]);

        return $parts === [] ? '' : implode(', ', $parts)."\n";
    }
}
