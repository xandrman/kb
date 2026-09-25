<?php

namespace App\Neuron\Retrieval;

use App\Enums\AccessLevel;
use App\Models\Document;
use App\Observability\Tracing;
use App\Services\KnowledgeGraph;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Document as Chunk;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\RAG\VectorStore\QdrantVectorStore;
use OpenTelemetry\API\Trace\SpanInterface;

/**
 * Hybrid knowledge base retrieval for one user: vector search plus graph expansion, only what their clearance opens (FR-5, FR-7).
 */
class KnowledgeBaseRetrieval implements RetrievalInterface
{
    /**
     * Retrieval instruction of the embedding model: queries carry it, indexed chunks do not (ADR-0010, п. 2).
     */
    private const string QUERY_INSTRUCTION = 'Given a question, retrieve passages that answer the question';

    /**
     * The last question and its embedding: the vector search and the equipment bridge rank by the same vector.
     *
     * @var array{question: string, embedding: list<float>}|null
     */
    private ?array $questionEmbedding = null;

    public function __construct(
        private readonly EmbeddingsProviderInterface $embeddings,
        private readonly QdrantVectorStore $chunks,
        private readonly KnowledgeGraph $knowledgeGraph,
        private readonly AccessLevel $clearance,
        private readonly Tracing $tracing,
    ) {}

    /**
     * Chunks closest to the question first, then the chunks the graph reaches from them.
     *
     * @return list<Chunk>
     */
    public function retrieve(Message $query): array
    {
        $question = (string) $query->getContent();
        $found = $this->vectorSearch($question);

        return [...$found, ...$this->graphExpansion($found, $question)];
    }

    /**
     * Chunks closest to the question among those the clearance opens.
     *
     * @return list<Chunk>
     */
    public function vectorSearch(string $question): array
    {
        return $this->tracing->span('rag.vector_search', function (SpanInterface $span) use ($question): array {
            $found = $this->searchVectors($question);
            $span->setAttribute('kb.chunks.found', count($found));

            return $found;
        }, ['kb.clearance' => $this->clearance->value]);
    }

    /**
     * @return list<Chunk>
     */
    private function searchVectors(string $question): array
    {
        $embedding = $this->embedQuestion($question);

        // ADR-0006: гриф — условие внутри поиска в Qdrant, а не отбор после него: недоступный чанк не займёт место в выдаче
        $found = array_values([...$this->chunks
            ->withFilters([$this->accessFilter()])
            ->similaritySearch($embedding)]);

        foreach ($found as $chunk) {
            $chunk->addMetadata('retrieved_by', 'vector');
        }

        // Совпадение по номеру акта или модели точнее сходства смысла: такие чанки идут первыми
        $identified = $this->searchIdentifiers($question);
        $identifiedIds = array_map(fn (Chunk $chunk): string => (string) $chunk->getId(), $identified);
        $found = [...$identified, ...array_filter($found, fn (Chunk $chunk): bool => ! in_array((string) $chunk->getId(), $identifiedIds, true))];

        return $this->describeDocuments(array_values($found));
    }

    /**
     * Chunks containing an identifier from the question — a service act number, a model code — closest to the question first.
     *
     * Эмбеддинг почти не различает номера: акт СЦ-714029 и акт СЦ-743718 для него одинаково близки к вопросу.
     * Без полнотекстового индекса условие text в Qdrant — точный поиск подстроки; гриф — в том же фильтре (ADR-0006).
     *
     * @return list<Chunk>
     */
    private function searchIdentifiers(string $question): array
    {
        $identified = [];

        foreach (array_slice(self::identifiers($question), 0, config('services.identifiers.limit')) as $identifier) {
            $points = $this->closestPoints(['must' => [['key' => 'content', 'match' => ['text' => $identifier]], $this->accessFilter()]], $question, config('services.identifiers.chunks'));

            foreach ($points as $point) {
                $chunk = $this->chunkFromPoint($point['id'], $point['payload']);
                $chunk->setScore($point['score']);
                $chunk->addMetadata('retrieved_by', 'identifier');
                $identified[$point['id']] ??= $chunk;
            }
        }

        return array_values($identified);
    }

    /**
     * Identifier-like tokens of the question: at least five characters with a digit, such as «СЦ-714029» or «SNR-UPS-LID-1500».
     *
     * @return list<string>
     */
    public static function identifiers(string $question): array
    {
        preg_match_all('/(?<![\p{L}\p{N}])[\p{L}\p{N}]+(?:[-\/.][\p{L}\p{N}]+)*/u', $question, $matches);

        return array_values(array_unique(array_filter(
            $matches[0],
            fn (string $token): bool => mb_strlen($token) >= 5 && preg_match('/\p{N}/u', $token) === 1,
        )));
    }

    /**
     * Names of the entities the graph expansion starts from, for the user to see what is being looked up.
     *
     * @param  list<Chunk>  $seeds
     * @return list<string>
     */
    public function expansionEntities(array $seeds, int $limit = 3): array
    {
        // Сущности лучших по сходству чанков — ближе всего к смыслу вопроса
        return $this->knowledgeGraph->mentionedEntityNames(
            array_map(fn (Chunk $chunk): string => (string) $chunk->getId(), array_slice($seeds, 0, 3)),
            $this->clearance,
            $limit,
        );
    }

    /**
     * Chunks the graph reaches from the seed chunks: by relations, then through the same equipment in other documents.
     *
     * @param  list<Chunk>  $seeds
     * @return list<Chunk>
     */
    public function graphExpansion(array $seeds, string $question): array
    {
        return $this->tracing->span('rag.graph_expansion', function (SpanInterface $span) use ($seeds, $question): array {
            $expansion = $this->expandThroughGraph($seeds);
            $bridged = $this->bridgeThroughEquipment($seeds, $expansion, $question);
            $span->setAttribute('kb.chunks.added', count($expansion) + count($bridged));
            $span->setAttribute('kb.chunks.bridged', count($bridged));

            return [...$expansion, ...$bridged];
        }, ['kb.chunks.seeds' => count($seeds)]);
    }

    /**
     * @param  list<Chunk>  $seeds
     * @return list<Chunk>
     */
    private function expandThroughGraph(array $seeds): array
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

            // Факты пути объясняют модели, почему чанк попал в контекст
            $expansion[] = $this->graphChunk($relation['chunk_id'], $points[$relation['chunk_id']]['payload'], $relation['facts']);
        }

        return $this->describeDocuments($expansion);
    }

    /**
     * Second hop of a multi-hop question: the equipment named in the best found chunks leads to other documents about it,
     * and for each model the chunks closest to the question are taken (act of repair → passport of the model).
     *
     * @param  list<Chunk>  $seeds
     * @param  list<Chunk>  $expansion
     * @return list<Chunk>
     */
    private function bridgeThroughEquipment(array $seeds, array $expansion, string $question): array
    {
        // Как и у expansionEntities, мост идёт от лучших чанков: от десятка слабых кандидатов он уводит к чужим моделям
        $candidates = $this->knowledgeGraph->equipmentBridgedChunks(
            array_map(fn (Chunk $chunk): string => (string) $chunk->getId(), array_slice($seeds, 0, 3)),
            $this->clearance,
            config('services.graph.bridge_candidates'),
        );

        $alreadyFound = array_map(fn (Chunk $chunk): string => (string) $chunk->getId(), [...$seeds, ...$expansion]);
        $chunkIdsByEquipment = [];
        foreach ($candidates as $candidate) {
            if (! in_array($candidate['chunk_id'], $alreadyFound, true)) {
                $chunkIdsByEquipment[$candidate['equipment']][] = $candidate['chunk_id'];
            }
        }

        $bridged = [];
        foreach ($chunkIdsByEquipment as $equipment => $chunkIds) {
            $filter = ['must' => [['has_id' => $chunkIds], $this->accessFilter()]];

            foreach ($this->closestPoints($filter, $question, config('services.graph.bridge_limit')) as $point) {
                $chunk = $this->graphChunk($point['id'], $point['payload'], ["{$equipment} — та же модель изделия, что в найденных фрагментах"]);
                $chunk->setScore($point['score']);
                $bridged[$point['id']] ??= $chunk;
            }
        }

        return $this->describeDocuments(array_values($bridged));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $facts
     */
    private function graphChunk(string $id, array $payload, array $facts): Chunk
    {
        $chunk = $this->chunkFromPoint($id, $payload);
        $chunk->addMetadata('retrieved_by', 'graph');
        $chunk->addMetadata('graph_facts', $facts);

        return $chunk;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function chunkFromPoint(string $id, array $payload): Chunk
    {
        $chunk = new Chunk($payload['content']);
        $chunk->id = $id;
        $chunk->sourceType = $payload['sourceType'];
        $chunk->sourceName = $payload['sourceName'];
        $chunk->metadata = array_diff_key($payload, array_flip(['content', 'sourceType', 'sourceName']));

        return $chunk;
    }

    /**
     * @return list<float>
     */
    private function embedQuestion(string $question): array
    {
        if ($this->questionEmbedding === null || $this->questionEmbedding['question'] !== $question) {
            $this->questionEmbedding = [
                'question' => $question,
                'embedding' => $this->embeddings->embedText('Instruct: '.self::QUERY_INSTRUCTION."\nQuery: {$question}"),
            ];
        }

        return $this->questionEmbedding['embedding'];
    }

    /**
     * Add the document each chunk comes from, so the answer does not mix instructions of different models.
     *
     * @param  list<Chunk>  $chunks
     * @return list<Chunk>
     */
    private function describeDocuments(array $chunks): array
    {
        $documents = Document::with('documentType')
            ->findMany(array_unique(array_map(fn (Chunk $chunk): mixed => $chunk->metadata['document_id'] ?? null, $chunks)))
            ->keyBy('id');

        foreach ($chunks as $chunk) {
            $document = $documents->get($chunk->metadata['document_id'] ?? null);

            if ($document !== null) {
                $chunk->addMetadata('document_name', $document->original_name);
                $chunk->addMetadata('document_type', $document->documentType?->name);
            }
        }

        return $chunks;
    }

    /**
     * Graph chunks are read from Qdrant under the same access filter as the search, as a second line of defence (FR-7).
     *
     * @param  list<string>  $ids
     * @return list<array{id: string, payload: array<string, mixed>}>
     */
    private function pointsWithIds(array $ids): array
    {
        return $this->qdrant()
            ->post($this->collectionUrl().'/points/scroll', [
                'filter' => ['must' => [['has_id' => $ids], $this->accessFilter()]],
                'limit' => count($ids),
                'with_payload' => true,
                'with_vector' => false,
            ])
            ->throw()
            ->json('result.points');
    }

    /**
     * The points passing the filter closest to the question; the filter carries the access condition (FR-7).
     *
     * @param  array{must: list<array<string, mixed>>}  $filter
     * @return list<array{id: string, score: float, payload: array<string, mixed>}>
     */
    private function closestPoints(array $filter, string $question, int $limit): array
    {
        return $this->qdrant()
            ->post($this->collectionUrl().'/points/query', [
                'query' => $this->embedQuestion($question),
                'filter' => $filter,
                'limit' => $limit,
                'with_payload' => true,
            ])
            ->throw()
            ->json('result.points');
    }

    private function qdrant(): PendingRequest
    {
        return Http::withHeaders(array_filter(['api-key' => config('services.qdrant.key')]));
    }

    private function collectionUrl(): string
    {
        return rtrim(config('services.qdrant.url'), '/').'/collections/'.config('services.qdrant.collection');
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
