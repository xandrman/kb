<?php

namespace App\Neuron\Retrieval;

use App\Enums\AccessLevel;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Document as Chunk;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\RAG\VectorStore\QdrantVectorStore;

/**
 * Knowledge base retrieval for one user: only chunks their clearance opens (FR-5, FR-7).
 */
class KnowledgeBaseRetrieval implements RetrievalInterface
{
    /**
     * Retrieval instruction of the embedding model: queries carry it, indexed chunks do not (ADR-0010, п. 2).
     */
    private const string QUERY_INSTRUCTION = 'Given a question, retrieve passages that answer the question';

    public function __construct(
        private readonly EmbeddingsProviderInterface $embeddings,
        private readonly QdrantVectorStore $chunks,
        private readonly AccessLevel $clearance,
    ) {}

    /**
     * Chunks closest to the question, most similar first.
     *
     * @return list<Chunk>
     */
    public function retrieve(Message $query): array
    {
        $embedding = $this->embeddings->embedText('Instruct: '.self::QUERY_INSTRUCTION."\nQuery: {$query->getContent()}");

        // ADR-0006: гриф — условие внутри поиска в Qdrant, а не отбор после него: недоступный чанк не займёт место в выдаче
        $allowedLevels = array_map(fn (AccessLevel $level): string => $level->value, $this->clearance->opens());

        return array_values([...$this->chunks
            ->withFilters([['key' => 'access_level', 'match' => ['any' => $allowedLevels]]])
            ->similaritySearch($embedding)]);
    }
}
