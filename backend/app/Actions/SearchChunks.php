<?php

namespace App\Actions;

use App\Enums\AccessLevel;
use NeuronAI\RAG\Document as Chunk;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorStore\QdrantVectorStore;

class SearchChunks
{
    /**
     * Retrieval instruction of the embedding model: queries carry it, indexed chunks do not (ADR-0010, п. 2).
     */
    private const string QUERY_INSTRUCTION = 'Given a question, retrieve passages that answer the question';

    public function __construct(
        private readonly EmbeddingsProviderInterface $embeddings,
        private readonly QdrantVectorStore $chunks,
    ) {}

    /**
     * Chunks closest to the question among those the user is cleared for, most similar first (FR-5, FR-7).
     *
     * @return list<Chunk>
     */
    public function handle(string $question, AccessLevel $clearance): array
    {
        $embedding = $this->embeddings->embedText('Instruct: '.self::QUERY_INSTRUCTION."\nQuery: {$question}");

        // ADR-0006: гриф — условие внутри поиска в Qdrant, а не отбор после него: недоступный чанк не займёт место в выдаче
        $allowedLevels = array_map(fn (AccessLevel $level): string => $level->value, $clearance->opens());

        return array_values([...$this->chunks
            ->withFilters([['key' => 'access_level', 'match' => ['any' => $allowedLevels]]])
            ->similaritySearch($embedding)]);
    }
}
