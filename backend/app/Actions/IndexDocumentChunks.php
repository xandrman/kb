<?php

namespace App\Actions;

use App\Contracts\DocumentStorage;
use App\Enums\AccessLevel;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Services\DoclingClient;
use NeuronAI\RAG\Document as Chunk;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

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
    ) {}

    /**
     * Chunk the stored extraction, embed the chunks and replace the document's points in Qdrant (FR-3).
     */
    public function handle(Document $document): void
    {
        $chunks = $this->docling->chunk($this->storage->getExtracted($document->digest), $document->digest.'.json');

        if ($chunks === []) {
            throw new RuntimeException('docling не выделил в документе ни одного чанка.');
        }

        // Документы кодируются без инструкции: префикс Instruct/Query нужен только запросу (ADR-0010, п. 2)
        $points = $this->embeddings->embedDocuments(array_map(
            fn (array $chunk): Chunk => $this->toPoint($document, $chunk),
            $chunks,
        ));

        // Повтор задачи и переиндексация не должны оставить точки прошлой нарезки
        $this->vectorStore->deleteBy(self::SOURCE_TYPE, (string) $document->id);
        $this->vectorStore->addDocuments($points);

        $document->update(['status' => DocumentStatus::Indexed, 'error' => null]);
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
