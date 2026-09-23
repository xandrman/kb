<?php

namespace App\Neuron\Retrieval;

use App\Enums\AccessLevel;
use App\Services\KnowledgeGraph;
use Illuminate\Support\Facades\Http;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Document as Chunk;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\RAG\VectorStore\QdrantVectorStore;

/**
 * Hybrid knowledge base retrieval for one user: vector search plus graph expansion, only what their clearance opens (FR-5, FR-7).
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
        private readonly KnowledgeGraph $knowledgeGraph,
        private readonly AccessLevel $clearance,
    ) {}

    /**
     * Chunks closest to the question first, then the chunks the graph reaches from them.
     *
     * @return list<Chunk>
     */
    public function retrieve(Message $query): array
    {
        $found = $this->vectorSearch((string) $query->getContent());

        return [...$found, ...$this->graphExpansion($found)];
    }

    /**
     * @return list<Chunk>
     */
    private function vectorSearch(string $question): array
    {
        $embedding = $this->embeddings->embedText('Instruct: '.self::QUERY_INSTRUCTION."\nQuery: {$question}");

        // ADR-0006: гриф — условие внутри поиска в Qdrant, а не отбор после него: недоступный чанк не займёт место в выдаче
        $found = array_values([...$this->chunks
            ->withFilters([$this->accessFilter()])
            ->similaritySearch($embedding)]);

        foreach ($found as $chunk) {
            $chunk->addMetadata('retrieved_by', 'vector');
        }

        return $found;
    }

    /**
     * @param  list<Chunk>  $seeds
     * @return list<Chunk>
     */
    private function graphExpansion(array $seeds): array
    {
        $related = $this->knowledgeGraph->relatedChunks(
            array_map(fn (Chunk $chunk): string => (string) $chunk->getId(), $seeds),
            $this->clearance,
            config('services.graph.expansion_limit'),
            config('services.graph.max_degree'),
        );

        if ($related === []) {
            return [];
        }

        $points = [];
        foreach ($this->pointsWithIds(array_column($related, 'chunk_id')) as $point) {
            $points[$point['id']] = $point;
        }

        $expansion = [];
        foreach ($related as $relation) {
            // Точки нет, если фильтр Qdrant её не пропустил или индекс отстал от графа — такой чанк не показываем
            if (! isset($points[$relation['chunk_id']])) {
                continue;
            }

            $payload = $points[$relation['chunk_id']]['payload'];

            $chunk = new Chunk($payload['content']);
            $chunk->id = $relation['chunk_id'];
            $chunk->sourceType = $payload['sourceType'];
            $chunk->sourceName = $payload['sourceName'];
            $chunk->metadata = array_diff_key($payload, array_flip(['content', 'sourceType', 'sourceName']));
            $chunk->addMetadata('retrieved_by', 'graph');
            // Факты пути объясняют модели, почему чанк попал в контекст
            $chunk->addMetadata('graph_facts', $relation['facts']);
            $expansion[] = $chunk;
        }

        return $expansion;
    }

    /**
     * Graph chunks are read from Qdrant under the same access filter as the search, as a second line of defence (FR-7).
     *
     * @param  list<string>  $ids
     * @return list<array{id: string, payload: array<string, mixed>}>
     */
    private function pointsWithIds(array $ids): array
    {
        $collectionUrl = rtrim(config('services.qdrant.url'), '/').'/collections/'.config('services.qdrant.collection');

        return Http::withHeaders(array_filter(['api-key' => config('services.qdrant.key')]))
            ->post("{$collectionUrl}/points/scroll", [
                'filter' => ['must' => [['has_id' => $ids], $this->accessFilter()]],
                'limit' => count($ids),
                'with_payload' => true,
                'with_vector' => false,
            ])
            ->throw()
            ->json('result.points');
    }

    /**
     * @return array{key: string, match: array{any: list<string>}}
     */
    private function accessFilter(): array
    {
        return [
            'key' => 'access_level',
            'match' => ['any' => array_map(fn (AccessLevel $level): string => $level->value, $this->clearance->opens())],
        ];
    }
}
