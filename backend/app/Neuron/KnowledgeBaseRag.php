<?php

namespace App\Neuron;

use App\Enums\AccessLevel;
use App\Neuron\Nodes\GroundedContextNode;
use App\Neuron\Nodes\ProgressiveRetrievalNode;
use App\Neuron\PostProcessor\DuplicateChunksPostProcessor;
use App\Neuron\PostProcessor\RerankerPostProcessor;
use App\Neuron\Retrieval\KnowledgeBaseRetrieval;
use App\Observability\TraceHttpRequests;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAILike;
use NeuronAI\RAG\Nodes\PostProcessNode;
use NeuronAI\RAG\Nodes\PreProcessNode;
use NeuronAI\RAG\PostProcessor\FixedThresholdPostProcessor;
use NeuronAI\RAG\RAG;
use NeuronAI\RAG\Retrieval\RetrievalInterface;

/**
 * Answers a question from the knowledge base as one user sees it (FR-5, FR-7, ADR-0022).
 */
class KnowledgeBaseRag extends RAG
{
    public function __construct(private readonly AccessLevel $clearance)
    {
        parent::__construct();
    }

    protected function provider(): AIProviderInterface
    {
        // Низкая температура: ответ пересказывает источники, а не сочиняет
        return new OpenAILike(
            baseUri: config('services.llm.url'),
            key: '',
            model: config('services.llm.model'),
            parameters: ['temperature' => 0.2],
            httpClient: TraceHttpRequests::neuronClient(),
        );
    }

    protected function instructions(): string
    {
        $refusal = GroundedContextNode::REFUSAL;

        return <<<PROMPT
            Ты — ассистент базы знаний сервисной документации по технике: руководств, инструкций, сервисных актов.
            Отвечай только по фрагментам документов из блока CONTEXT. Собственные знания не используй.
            Если во фрагментах нет ответа на вопрос, ответь ровно: «{$refusal}» — и ничего больше.
            Отвечай на языке вопроса, кратко и по существу; процедуры излагай по шагам.
            PROMPT;
    }

    protected function retrieval(): RetrievalInterface
    {
        return app(KnowledgeBaseRetrieval::class, ['clearance' => $this->clearance]);
    }

    protected function postProcessors(): array
    {
        // Порог по оценке реранкера: оставшиеся ниже него фрагменты — не источники, и без источников ответа нет
        return $this->postProcessors !== [] ? $this->postProcessors : [
            app(RerankerPostProcessor::class),
            new DuplicateChunksPostProcessor,
            new FixedThresholdPostProcessor(config('services.reranker.threshold')),
        ];
    }

    protected function ragNodes(): array
    {
        return [
            new PreProcessNode($this->preProcessors()),
            new ProgressiveRetrievalNode($this->resolveRetrieval()),
            new PostProcessNode($this->postProcessors()),
            new GroundedContextNode($this->resolveInstructions(), $this->bootstrapTools()),
        ];
    }
}
