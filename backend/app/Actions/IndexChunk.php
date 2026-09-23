<?php

namespace App\Actions;

use App\Enums\AccessLevel;
use App\Models\Document;
use App\Neuron\Output\ExtractedGraph;
use App\Services\KnowledgeGraph;
use Illuminate\Support\Facades\Log;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\RAG\Document as Point;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\StructuredOutput\Deserializer\DeserializerException;

class IndexChunk
{
    public function __construct(
        private readonly MaskPersonalData $maskPersonalData,
        private readonly EmbeddingsProviderInterface $embeddings,
        private readonly VectorStoreInterface $vectorStore,
        private readonly ExtractChunkGraph $extractGraph,
        private readonly KnowledgeGraph $knowledgeGraph,
    ) {}

    /**
     * Mask personal data, then put the chunk into both indexes: a point in Qdrant (FR-3) and its graph in Neo4j (FR-4).
     *
     * Повтор безопасен: точка и граф чанка перезаписываются по стабильному id.
     *
     * @param  array{text: string, chunk_index: int, headings: list<string>|null, page_numbers: list<int>|null}  $chunk
     * @return int number of distinct personal data values masked
     */
    public function handle(Document $document, array $chunk): int
    {
        // ТЗ 4.4: дальше этой строки — ни в эмбеддинг, ни в Qdrant, ни в LLM графа — текст идёт только маскированным
        ['text' => $text, 'count' => $masked] = $this->maskPersonalData->handle($chunk['text']);
        $chunkId = IndexDocumentChunks::chunkId($document->id, $chunk['chunk_index']);

        // Документы кодируются без инструкции: префикс Instruct/Query нужен только запросу (ADR-0010, п. 2)
        $this->vectorStore->addDocument($this->embeddings->embedDocument($this->toPoint($document, $chunkId, $text, $chunk)));

        try {
            $graph = $this->extractGraph->handle($text, $document->original_name);
        } catch (AgentException|DeserializerException $exception) {
            $graph = new ExtractedGraph;

            // Ответ не прошёл схему и после повтора внутри Neuron; при temperature 0 повтор задачи даст то же — чанк остаётся без графа
            Log::warning('Граф чанка не извлечён', [
                'document_id' => $document->id,
                'chunk_id' => $chunkId,
                'error' => $exception->getMessage(),
            ]);
        }

        // Чанк пишется и без сущностей: иначе от прошлой нарезки остался бы его граф
        $this->knowledgeGraph->writeChunk($document, $chunkId, $graph);

        return $masked;
    }

    /**
     * @param  array{text: string, chunk_index: int, headings: list<string>|null, page_numbers: list<int>|null}  $chunk
     */
    private function toPoint(Document $document, string $chunkId, string $text, array $chunk): Point
    {
        $point = new Point($text);
        $point->id = $chunkId;
        $point->sourceType = IndexDocumentChunks::SOURCE_TYPE;
        $point->sourceName = (string) $document->id;

        /** @var AccessLevel $accessLevel */
        $accessLevel = $document->access_level;

        // Гриф и подразделение — копия из PostgreSQL для фильтра при поиске (ADR-0006, ADR-0014); страницы — диапазон (ADR-0012, п. 1)
        $point->metadata = [
            'document_id' => $document->id,
            'chunk_index' => $chunk['chunk_index'],
            'page_numbers' => $chunk['page_numbers'] ?? [],
            'headings' => $chunk['headings'] ?? [],
            'access_level' => $accessLevel->value,
            'owner_department' => $document->owner_department,
            'sku' => $document->sku,
            'document_type_id' => $document->document_type_id,
        ];

        return $point;
    }
}
