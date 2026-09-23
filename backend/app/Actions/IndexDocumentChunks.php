<?php

namespace App\Actions;

use App\Contracts\DocumentStorage;
use App\Enums\AccessLevel;
use App\Enums\DocumentStatus;
use App\Jobs\ExtractGraph;
use App\Models\Document;
use App\Services\ChunkLanguage;
use App\Services\DoclingClient;
use App\Services\KnowledgeGraph;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use NeuronAI\RAG\Document as Chunk;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

class IndexDocumentChunks
{
    /**
     * Point source type in Qdrant; the source name is the document id.
     */
    public const string SOURCE_TYPE = 'document';

    /**
     * UUIDv5 namespace of chunk ids.
     */
    private const string CHUNK_ID_NAMESPACE = '0b6f3c1e-8f2a-4d5b-9c7e-2a4d6f8b1c3e';

    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly DoclingClient $docling,
        private readonly EmbeddingsProviderInterface $embeddings,
        private readonly VectorStoreInterface $vectorStore,
        private readonly KnowledgeGraph $knowledgeGraph,
        private readonly ChunkLanguage $language,
    ) {}

    /**
     * Chunk the stored extraction, embed the chunks and replace the document's points in Qdrant (FR-3).
     */
    public function handle(Document $document): void
    {
        $startedAt = microtime(true);

        $allChunks = $this->docling->chunk($this->storage->getExtracted($document->digest), $document->digest.'.json');

        if ($allChunks === []) {
            throw new RuntimeException('docling не выделил в документе ни одного чанка.');
        }

        // Корпус русско-английский (ТЗ 4.3): разделы многоязычных руководств на других языках не индексируются.
        // Номер чанка сохраняется — от него зависит id точки и узла графа
        $chunks = array_values(array_filter($allChunks, fn (array $chunk): bool => $this->language->isRussianOrEnglish($chunk['text'])));

        if ($chunks === []) {
            throw new RuntimeException('В документе нет текста на русском или английском языке.');
        }

        // Документы кодируются без инструкции: префикс Instruct/Query нужен только запросу (ADR-0010, п. 2)
        $points = $this->embeddings->embedDocuments(array_map(
            fn (array $chunk): Chunk => $this->toPoint($document, $chunk),
            $chunks,
        ));

        // Повтор задачи и переиндексация не должны оставить точки прошлой нарезки
        $this->vectorStore->deleteBy(self::SOURCE_TYPE, (string) $document->id);
        $this->vectorStore->addDocuments($points);

        $document->update([
            'status' => DocumentStatus::Indexed,
            'indexing_time' => microtime(true) - $startedAt,
            'error' => null,
        ]);

        $this->dispatchGraphExtraction($document, $chunks);
    }

    /**
     * One job per chunk: the graph workers run them in parallel against the LLM (ADR-0016).
     *
     * @param  list<array{text: string, chunk_index: int, headings: list<string>|null, page_numbers: list<int>|null}>  $chunks
     */
    private function dispatchGraphExtraction(Document $document, array $chunks): void
    {
        $documentId = $document->id;

        // Чанки прошлой нарезки, которых нет в новой, задачи пакета не перезапишут
        $this->knowledgeGraph->forgetDocument($document);

        Bus::batch(array_map(
            fn (array $chunk): ExtractGraph => new ExtractGraph($document, self::chunkId($documentId, $chunk['chunk_index']), $chunk['text']),
            $chunks,
        ))
            ->name("graph:document:{$documentId}")
            ->onQueue('graph')
            ->then(static function (Batch $batch) use ($documentId): void {
                // От постановки пакета до последней задачи: включает ожидание в очереди graph за другими документами
                Document::find($documentId)?->update([
                    'status' => DocumentStatus::Processed,
                    'graph_time' => $batch->createdAt->diffInSeconds(now()),
                    'error' => null,
                ]);
            })
            ->catch(static function (Batch $batch, Throwable $exception) use ($documentId): void {
                $document = Document::find($documentId);

                if ($document !== null) {
                    app(MarkDocumentFailed::class)->handle($document, $exception->getMessage());
                }
            })
            ->dispatch();
    }

    /**
     * Stable chunk id: the Qdrant point and the graph chunk node share it, so reindexing keeps the link.
     */
    public static function chunkId(int $documentId, int $chunkIndex): string
    {
        return Uuid::uuid5(self::CHUNK_ID_NAMESPACE, "{$documentId}:{$chunkIndex}")->toString();
    }

    /**
     * @param  array{text: string, chunk_index: int, headings: list<string>|null, page_numbers: list<int>|null}  $chunk
     */
    private function toPoint(Document $document, array $chunk): Chunk
    {
        $point = new Chunk($chunk['text']);
        $point->id = self::chunkId($document->id, $chunk['chunk_index']);
        $point->sourceType = self::SOURCE_TYPE;
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
