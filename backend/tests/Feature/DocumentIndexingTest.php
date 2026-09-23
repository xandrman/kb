<?php

namespace Tests\Feature;

use App\Actions\IndexDocumentChunks;
use App\Enums\AccessLevel;
use App\Enums\DocumentStatus;
use App\Jobs\ExtractGraph;
use App\Jobs\IndexDocument;
use App\Models\Document;
use App\Neuron\GraphExtractor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Testing\Fakes\BatchFake;
use Illuminate\Support\Testing\Fakes\PendingBatchFake;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\RAG\Document as Chunk;
use NeuronAI\RAG\Embeddings\AbstractEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use RuntimeException;
use Tests\TestCase;

class DocumentIndexingTest extends TestCase
{
    use RefreshDatabase;

    private const string DOCLING_URL = 'http://docling.test';

    public const array EMBEDDING = [0.6, 0.8];

    private const string EMPTY_GRAPH = '{"entities":[],"relations":[]}';

    private MemoryVectorStore $vectorStore;

    private FakeAIProvider $llm;

    /**
     * @var list<string>
     */
    private array $embeddedTexts = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');

        config([
            'services.docling.url' => self::DOCLING_URL,
            'services.docling.chunk_tokenizer' => '/opt/tokenizers/embedding',
            'services.docling.chunk_max_tokens' => 512,
        ]);

        $this->vectorStore = new MemoryVectorStore;
        $this->app->instance(VectorStoreInterface::class, $this->vectorStore);

        $embeddedTexts = &$this->embeddedTexts;
        $this->app->instance(EmbeddingsProviderInterface::class, new class($embeddedTexts) extends AbstractEmbeddingsProvider
        {
            /**
             * @param  list<string>  $embeddedTexts
             */
            public function __construct(private array &$embeddedTexts) {}

            public function embedText(string $text): array
            {
                $this->embeddedTexts[] = $text;

                return DocumentIndexingTest::EMBEDDING;
            }
        });

        $this->fakeLlmAnswers(array_fill(0, 5, self::EMPTY_GRAPH));
    }

    public function test_the_stored_extraction_is_chunked_by_docling_without_converting_the_original_again(): void
    {
        $this->fakeChunks([$this->chunk(0, 'Начало работы')]);
        $document = $this->extractedDocument();

        app()->call([new IndexDocument($document), 'handle']);

        Http::assertSent(fn (Request $request): bool => $request->url() === self::DOCLING_URL.'/v1/chunk/hybrid/file'
            && $this->multipartField($request, 'convert_from_formats') === 'json_docling'
            && $this->multipartField($request, 'chunking_tokenizer') === '/opt/tokenizers/embedding'
            && $this->multipartField($request, 'chunking_max_tokens') === '512'
            && $this->multipartField($request, 'files') === '{"schema_name":"DoclingDocument"}');
    }

    public function test_chunks_are_embedded_as_is_and_written_with_access_metadata(): void
    {
        $this->fakeChunks([
            $this->chunk(0, "Начало работы\nПодключите монитор.", ['Начало работы'], [3]),
            $this->chunk(1, "Гарантия\nАбзац на границе страниц.", ['Гарантия'], [4, 5]),
        ]);
        $document = $this->extractedDocument([
            'access_level' => AccessLevel::Confidential,
            'owner_department' => 'Сервис',
            'sku' => 'SKU-00042',
        ]);

        app()->call([new IndexDocument($document), 'handle']);

        $this->assertSame(["Начало работы\nПодключите монитор.", "Гарантия\nАбзац на границе страниц."], $this->embeddedTexts);

        $points = $this->points();
        $this->assertCount(2, $points);
        $this->assertSame(self::EMBEDDING, $points[1]->getEmbedding());
        $this->assertSame(IndexDocumentChunks::SOURCE_TYPE, $points[1]->getSourceType());
        $this->assertSame((string) $document->id, $points[1]->getSourceName());
        $this->assertSame([
            'document_id' => $document->id,
            'chunk_index' => 1,
            'page_numbers' => [4, 5],
            'headings' => ['Гарантия'],
            'access_level' => 'confidential',
            'owner_department' => 'Сервис',
            'sku' => 'SKU-00042',
            'document_type_id' => $document->document_type_id,
        ], $points[1]->metadata);

        $this->assertSame(DocumentStatus::Processed, $document->refresh()->status);
    }

    public function test_reindexing_replaces_only_the_points_of_the_same_document(): void
    {
        $document = $this->extractedDocument();
        $this->vectorStore->addDocuments([
            $this->storedPoint((string) $document->id, 'Прошлая нарезка'),
            $this->storedPoint('999', 'Чужой документ'),
        ]);
        $this->fakeChunks([$this->chunk(0, 'Новая нарезка')]);

        app()->call([new IndexDocument($document), 'handle']);

        $this->assertSame(['Чужой документ', 'Новая нарезка'], array_map(fn (Chunk $point): string => $point->getContent(), $this->points()));
    }

    public function test_chunk_ids_are_stable_across_reindexing(): void
    {
        $this->fakeChunks([$this->chunk(0, 'Начало работы'), $this->chunk(1, 'Гарантия')]);
        $document = $this->extractedDocument();

        app()->call([new IndexDocument($document), 'handle']);
        $firstIds = array_map(fn (Chunk $point): string|int => $point->getId(), $this->points());
        app()->call([new IndexDocument($document), 'handle']);
        $secondIds = array_map(fn (Chunk $point): string|int => $point->getId(), $this->points());

        $this->assertSame([
            IndexDocumentChunks::chunkId($document->id, 0),
            IndexDocumentChunks::chunkId($document->id, 1),
        ], $firstIds);
        $this->assertSame($firstIds, $secondIds);
        $this->assertNotSame(IndexDocumentChunks::chunkId($document->id, 0), IndexDocumentChunks::chunkId($document->id + 1, 0));
    }

    public function test_every_chunk_goes_to_the_llm_with_the_document_name_and_the_document_is_processed(): void
    {
        $this->fakeChunks([$this->chunk(0, 'Начало работы'), $this->chunk(1, 'Гарантия')]);
        $document = $this->extractedDocument(['original_name' => 'Optix_MPG341QR_ru.pdf']);

        app()->call([new IndexDocument($document), 'handle']);

        $this->llm->assertMethodCallCount('structured', 2);
        $this->llm->assertSent(fn (RequestRecord $record): bool => $record->messages[0]->getContent() === "Документ: Optix_MPG341QR_ru.pdf\n\nФрагмент:\nГарантия");
        $this->assertSame(DocumentStatus::Processed, $document->refresh()->status);
    }

    public function test_a_chunk_with_an_invalid_llm_answer_is_skipped(): void
    {
        // Neuron повторяет запрос один раз: оба ответа не JSON
        $this->fakeLlmAnswers(['не JSON', 'снова не JSON', self::EMPTY_GRAPH]);
        $this->fakeChunks([$this->chunk(0, 'Начало работы'), $this->chunk(1, 'Гарантия')]);
        $document = $this->extractedDocument();

        app()->call([new IndexDocument($document), 'handle']);

        $this->llm->assertMethodCallCount('structured', 3);
        $this->assertSame(DocumentStatus::Processed, $document->refresh()->status);
    }

    public function test_a_failed_graph_batch_fails_the_document(): void
    {
        // Синхронная очередь выполняет задачи пакета внутри его транзакции и откатывает запись статуса — обработчик вызываем напрямую
        Bus::fake();
        $this->fakeChunks([$this->chunk(0, 'Начало работы')]);
        $document = $this->extractedDocument();

        app()->call([new IndexDocument($document), 'handle']);

        Bus::assertBatched(function (PendingBatchFake $batch) use ($document): bool {
            $batch->catchCallbacks()[0](new BatchFake('fake', $batch->name, 1, 1, 1, [], [], CarbonImmutable::now()), new RuntimeException('vLLM недоступен'));

            return $batch->name === "graph:document:{$document->id}"
                && $batch->queue() === 'graph'
                && $batch->jobs->count() === 1
                && $batch->jobs->first() instanceof ExtractGraph
                && $batch->jobs->first()->chunkId === IndexDocumentChunks::chunkId($document->id, 0);
        });

        $document->refresh();
        $this->assertSame(DocumentStatus::Failed, $document->status);
        $this->assertSame('vLLM недоступен', $document->error);
    }

    public function test_an_unparsable_extraction_fails_the_document_without_retrying(): void
    {
        Http::fake(['docling.test/v1/chunk/hybrid/file' => Http::response([
            'chunks' => [],
            'documents' => [['status' => 'failure', 'errors' => [['error_message' => 'The document backend could not parse the input.']]]],
            'processing_time' => 0.01,
        ])]);
        $document = $this->extractedDocument();

        $job = (new IndexDocument($document))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $job->assertFailed();
        $this->assertSame([], $this->points());
    }

    public function test_a_document_without_chunks_is_not_marked_as_indexed(): void
    {
        $this->fakeChunks([]);
        $document = $this->extractedDocument();

        $job = (new IndexDocument($document))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $job->assertFailed();
        $this->assertSame(DocumentStatus::Extracted, $document->refresh()->status);
    }

    /**
     * @param  list<string>  $answers
     */
    private function fakeLlmAnswers(array $answers): void
    {
        $this->llm = new FakeAIProvider(...array_map(fn (string $answer): AssistantMessage => new AssistantMessage($answer), $answers));
        $this->app->bind(GraphExtractor::class, fn (): GraphExtractor => (new GraphExtractor)->setAiProvider($this->llm));
    }

    /**
     * @param  list<array<string, mixed>>  $chunks
     */
    private function fakeChunks(array $chunks): void
    {
        Http::fake(['docling.test/v1/chunk/hybrid/file' => Http::response([
            'chunks' => $chunks,
            'documents' => [['status' => 'success', 'errors' => []]],
            'processing_time' => 1.04,
        ])]);
    }

    /**
     * @param  list<string>  $headings
     * @param  list<int>  $pageNumbers
     * @return array<string, mixed>
     */
    private function chunk(int $index, string $text, array $headings = ['Раздел'], array $pageNumbers = [1]): array
    {
        return [
            'filename' => 'digest.json',
            'chunk_index' => $index,
            'text' => $text,
            'raw_text' => null,
            'num_tokens' => 12,
            'headings' => $headings,
            'captions' => null,
            'doc_items' => ['#/texts/'.$index],
            'page_numbers' => $pageNumbers,
            'metadata' => ['has_image' => false],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function extractedDocument(array $attributes = []): Document
    {
        $document = Document::factory()->create(['status' => DocumentStatus::Extracted, ...$attributes]);
        Storage::disk('documents')->put(
            'extracted/'.substr($document->digest, 0, 2).'/'.$document->digest.'.json',
            '{"schema_name":"DoclingDocument"}',
        );

        return $document;
    }

    private function storedPoint(string $sourceName, string $content): Chunk
    {
        $point = new Chunk($content);
        $point->sourceType = IndexDocumentChunks::SOURCE_TYPE;
        $point->sourceName = $sourceName;
        $point->embedding = self::EMBEDDING;

        return $point;
    }

    /**
     * @return list<Chunk>
     */
    private function points(): array
    {
        return array_values((fn (): array => $this->documents)->call($this->vectorStore));
    }

    private function multipartField(Request $request, string $name): ?string
    {
        foreach ($request->data() as $part) {
            if (($part['name'] ?? null) === $name) {
                return (string) $part['contents'];
            }
        }

        return null;
    }
}
