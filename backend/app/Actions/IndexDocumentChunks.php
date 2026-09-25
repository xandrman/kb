<?php

namespace App\Actions;

use App\Contracts\DocumentStorage;
use App\Enums\DocumentStatus;
use App\Jobs\ProcessChunk;
use App\Models\Document;
use App\Services\ChunkLanguage;
use App\Services\DoclingClient;
use App\Services\KnowledgeGraph;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
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
        private readonly VectorStoreInterface $vectorStore,
        private readonly KnowledgeGraph $knowledgeGraph,
        private readonly ChunkLanguage $language,
    ) {}

    /**
     * Chunk the stored extraction and hand every chunk to its own job: masking, vector index, graph (FR-3, FR-4).
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

        // Повтор задачи и переиндексация не должны оставить точки и граф прошлой нарезки
        $this->forget($document);

        $document->update([
            'status' => DocumentStatus::Indexed,
            'indexing_time' => microtime(true) - $startedAt,
            'personal_data_count' => 0,
            'error' => null,
        ]);

        $this->dispatchChunks($document, $chunks);
    }

    /**
     * Remove the document from both indexes.
     */
    public function forget(Document $document): void
    {
        $this->vectorStore->deleteBy(self::SOURCE_TYPE, (string) $document->id);
        $this->knowledgeGraph->forgetDocument($document);
    }

    /**
     * One job per chunk: the graph workers run them in parallel against the LLM (ADR-0016).
     *
     * @param  list<array{text: string, chunk_index: int, headings: list<string>|null, page_numbers: list<int>|null}>  $chunks
     */
    private function dispatchChunks(Document $document, array $chunks): void
    {
        $documentId = $document->id;

        Bus::batch(array_map(fn (array $chunk): ProcessChunk => new ProcessChunk($document, $chunk), $chunks))
            ->name("chunks:document:{$documentId}")
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
                    // Документ без части чанков не должен отвечать на вопросы: индексы очищаются целиком
                    app(IndexDocumentChunks::class)->forget($document);
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
}
