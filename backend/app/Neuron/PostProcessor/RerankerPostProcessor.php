<?php

namespace App\Neuron\PostProcessor;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\PostProcessor\LocalAIRerankerPostProcessor;

/**
 * Reorders retrieved chunks with kb-vllm-reranker and keeps the best ones (ADR-0011); its score replaces the vector one.
 */
class RerankerPostProcessor extends LocalAIRerankerPostProcessor
{
    public function process(Message $question, array $documents): array
    {
        // Без кандидатов в сервис не ходим: реранкеру нечего упорядочивать
        return $documents === [] ? [] : parent::process($question, $documents);
    }
}
