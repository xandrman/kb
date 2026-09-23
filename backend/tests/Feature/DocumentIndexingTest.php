<?php

namespace Tests\Feature;

use App\Actions\IndexDocumentChunks;
use App\Enums\AccessLevel;
use App\Enums\DocumentStatus;
use App\Jobs\IndexDocument;
use App\Jobs\ProcessChunk;
use App\Models\Document;
use App\Neuron\GraphExtractor;
use App\Neuron\PersonalDataDetector;
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
use NeuronAI\RAG\GraphStore\GraphStoreInterface;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use RuntimeException;
use Tests\Fakes\FakeGraphStore;
use Tests\TestCase;
use Throwable;

class DocumentIndexingTest extends TestCase
{
    use RefreshDatabase;

    private const string DOCLING_URL = 'http://docling.test';

    public const array EMBEDDING = [0.6, 0.8];

    private const string EMPTY_GRAPH = '{"entities":[],"relations":[]}';

    private MemoryVectorStore $vectorStore;

    private FakeAIProvider $llm;

    private FakeAIProvider $personalDataLlm;

    private FakeGraphStore $graphStore;

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
        $this->fakePersonalData(array_fill(0, 5, []));

        $this->graphStore = new FakeGraphStore;
        $this->app->instance(GraphStoreInterface::class, $this->graphStore);
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

        $document->refresh();
        $this->assertSame(DocumentStatus::Processed, $document->status);
        $this->assertIsFloat($document->indexing_time);
        $this->assertGreaterThanOrEqual(0.0, $document->indexing_time);
    }

    public function test_chunks_in_languages_other_than_russian_and_english_are_not_indexed(): void
    {
        $this->fakeChunks([
            $this->chunk(0, 'Холодильник предназначен для замораживания свежих продуктов и хранения замороженных.'),
            $this->chunk(1, 'Холодильник відповідно до малюнку 1 призначений для заморожування свіжих харчових продуктів.'),
            $this->chunk(2, 'Do not place hot food in the refrigerator and keep the door closed when it is not used.'),
        ]);
        $document = $this->extractedDocument();

        app()->call([new IndexDocument($document), 'handle']);

        $points = $this->points();
        $this->assertSame([0, 2], array_map(fn (Chunk $point): int => $point->metadata['chunk_index'], $points));
        $this->assertSame(IndexDocumentChunks::chunkId($document->id, 2), $points[1]->getId());
        $this->llm->assertMethodCallCount('structured', 2);
    }

    public function test_a_document_with_no_russian_or_english_text_fails(): void
    {
        $this->fakeChunks([$this->chunk(0, 'Холодильник відповідно до малюнку 1 призначений для заморожування свіжих харчових продуктів.')]);
        $document = $this->extractedDocument();

        $job = (new IndexDocument($document))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $job->assertFailed();
        $this->assertSame([], $this->points());
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

    public function test_extracted_entities_and_relations_are_written_to_the_graph_with_the_document_access(): void
    {
        $this->fakeLlmAnswers([json_encode([
            'entities' => [
                ['name' => 'MSI Optix MPG341QR', 'type' => 'Equipment'],
                ['name' => 'Нет изображения', 'type' => 'Fault'],
            ],
            'relations' => [['source' => 'MSI Optix MPG341QR', 'type' => 'HAS_FAULT', 'target' => 'Нет изображения']],
        ], JSON_UNESCAPED_UNICODE)]);
        $this->fakeChunks([$this->chunk(0, 'Нет изображения на мониторе')]);
        $document = $this->extractedDocument(['access_level' => AccessLevel::Confidential, 'owner_department' => 'Сервис']);

        app()->call([new IndexDocument($document), 'handle']);

        $chunkId = IndexDocumentChunks::chunkId($document->id, 0);
        $this->assertGraphQuery('MERGE (chunk:Chunk {id: $chunkId})', [
            'chunkId' => $chunkId,
            'entities' => [
                ['key' => 'Equipment:msi optix mpg341qr', 'name' => 'MSI Optix MPG341QR', 'type' => 'Equipment'],
                ['key' => 'Fault:нет изображения', 'name' => 'Нет изображения', 'type' => 'Fault'],
            ],
            'documentId' => $document->id,
            'accessLevel' => 'confidential',
            'accessRank' => 2,
            'department' => 'Сервис',
        ]);
        $this->assertGraphQuery('CREATE (source)-[:RELATES', [
            'chunkId' => $chunkId,
            'relations' => [['source' => 'Equipment:msi optix mpg341qr', 'type' => 'HAS_FAULT', 'target' => 'Fault:нет изображения']],
            'documentId' => $document->id,
            'accessLevel' => 'confidential',
            'accessRank' => 2,
            'department' => 'Сервис',
        ]);
        $this->assertGraphQuery('max(chunk.access_rank)', [
            'keys' => ['Equipment:msi optix mpg341qr', 'Fault:нет изображения'],
            'levels' => ['public', 'internal', 'confidential'],
        ]);
    }

    public function test_a_merged_synonym_is_written_into_its_canonical_entity(): void
    {
        $this->graphStore->responder = fn (string $query): array => str_contains($query, ':ALIAS_OF]')
            ? [['key' => 'Equipment:msi optix mag301rf', 'canonical' => 'Equipment:optix mag301rf']]
            : [];
        $this->fakeLlmAnswers([json_encode([
            'entities' => [
                ['name' => 'MSI Optix MAG301RF', 'type' => 'Equipment'],
                ['name' => 'Нет изображения', 'type' => 'Fault'],
            ],
            'relations' => [['source' => 'MSI Optix MAG301RF', 'type' => 'HAS_FAULT', 'target' => 'Нет изображения']],
        ], JSON_UNESCAPED_UNICODE)]);
        $this->fakeChunks([$this->chunk(0, 'Нет изображения')]);
        $document = $this->extractedDocument();

        app()->call([new IndexDocument($document), 'handle']);

        $this->assertSame(
            ['Equipment:optix mag301rf', 'Fault:нет изображения'],
            array_column($this->graphStore->parametersOf('MERGE (chunk:Chunk {id: $chunkId})')[0]['entities'], 'key'),
        );
        $this->assertSame(
            [['source' => 'Equipment:optix mag301rf', 'type' => 'HAS_FAULT', 'target' => 'Fault:нет изображения']],
            $this->graphStore->parametersOf('CREATE (source)-[:RELATES')[0]['relations'],
        );
    }

    public function test_reindexing_forgets_the_previous_graph_of_the_document_first(): void
    {
        $this->fakeChunks([$this->chunk(0, 'Начало работы')]);
        $document = $this->extractedDocument();

        app()->call([new IndexDocument($document), 'handle']);

        $statements = array_column($this->graphStore->queries, 0);
        $forget = array_search('MATCH (chunk:Chunk {document_id: $documentId}) DETACH DELETE chunk', $statements, true);
        $write = array_search(true, array_map(fn (string $query): bool => str_contains($query, 'MERGE (chunk:Chunk {id: $chunkId})'), $statements), true);
        $this->assertIsInt($forget);
        $this->assertIsInt($write);
        $this->assertLessThan($write, $forget);
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

    public function test_a_finished_graph_batch_records_how_long_the_graph_took(): void
    {
        CarbonImmutable::setTestNow('2026-09-23 12:00:42');
        Bus::fake();
        $this->fakeChunks([$this->chunk(0, 'Начало работы')]);
        $document = $this->extractedDocument();

        app()->call([new IndexDocument($document), 'handle']);

        Bus::assertBatched(function (PendingBatchFake $batch): bool {
            $batch->thenCallbacks()[0](new BatchFake('fake', $batch->name, 1, 0, 0, [], [], CarbonImmutable::parse('2026-09-23 12:00:00')));

            return true;
        });

        $document->refresh();
        $this->assertSame(DocumentStatus::Processed, $document->status);
        $this->assertSame(42.0, $document->graph_time);
    }

    public function test_a_failed_graph_batch_fails_the_document(): void
    {
        // Синхронная очередь выполняет задачи пакета внутри его транзакции и откатывает запись статуса — обработчик вызываем напрямую
        Bus::fake();
        $this->fakeChunks([$this->chunk(0, 'Начало работы')]);
        $document = $this->extractedDocument();

        app()->call([new IndexDocument($document), 'handle']);

        // Точка, записанная задачей чанка до сбоя пакета
        $this->vectorStore->addDocuments([$this->storedPoint((string) $document->id, 'Начало работы')]);
        $this->graphStore->queries = [];

        Bus::assertBatched(function (PendingBatchFake $batch) use ($document): bool {
            $batch->catchCallbacks()[0](new BatchFake('fake', $batch->name, 1, 1, 1, [], [], CarbonImmutable::now()), new RuntimeException('vLLM недоступен'));

            return $batch->name === "chunks:document:{$document->id}"
                && $batch->queue() === 'graph'
                && $batch->jobs->count() === 1
                && $batch->jobs->first() instanceof ProcessChunk
                && $batch->jobs->first()->chunk['chunk_index'] === 0;
        });

        $document->refresh();
        $this->assertSame(DocumentStatus::Failed, $document->status);
        $this->assertSame('vLLM недоступен', $document->error);
        // Документ без части чанков не отвечает на вопросы: индексы очищены целиком
        $this->assertSame([], $this->points());
        $this->assertNotSame([], $this->graphStore->parametersOf('MATCH (chunk:Chunk {document_id: $documentId}) DETACH DELETE chunk'));
    }

    public function test_personal_data_is_masked_before_it_reaches_any_index(): void
    {
        $this->fakePersonalData([[
            ['text' => 'Иванов Пётр Сергеевич', 'type' => 'person_name'],
            ['text' => '+7 912 345-67-89', 'type' => 'phone'],
        ]]);
        $this->fakeChunks([$this->chunk(0, "Акт приёма в ремонт. Клиент: Иванов Пётр Сергеевич, тел. +7 912 345-67-89.\nНеисправность: нет изображения.")]);
        $document = $this->extractedDocument();

        app()->call([new IndexDocument($document), 'handle']);

        $masked = "Акт приёма в ремонт. Клиент: [ФИО 1], тел. [ТЕЛЕФОН 1].\nНеисправность: нет изображения.";
        $this->assertSame([$masked], $this->embeddedTexts);
        $this->assertSame($masked, $this->points()[0]->getContent());
        $this->llm->assertSent(fn (RequestRecord $record): bool => str_contains((string) $record->messages[0]->getContent(), $masked));
        $this->assertSame(2, $document->refresh()->personal_data_count);
    }

    public function test_an_unavailable_masking_model_keeps_the_chunk_out_of_the_indexes(): void
    {
        $this->fakePersonalData([]);
        $this->fakeChunks([$this->chunk(0, 'Клиент: Иванов Пётр Сергеевич.')]);
        $document = $this->extractedDocument();

        try {
            app()->call([new IndexDocument($document), 'handle']);
        } catch (Throwable) {
            // Синхронная очередь пробрасывает исключение задачи чанка
        }

        $this->assertSame([], $this->embeddedTexts);
        $this->assertSame([], $this->points());
        $this->llm->assertNothingSent();
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
     * @param  array<string, mixed>  $parameters
     */
    private function assertGraphQuery(string $fragment, array $parameters): void
    {
        $matching = array_filter($this->graphStore->queries, fn (array $query): bool => str_contains($query[0], $fragment));

        $this->assertNotEmpty($matching, "Нет запроса к графу с «{$fragment}»");
        $this->assertSame($parameters, array_values($matching)[0][1]);
    }

    /**
     * @param  list<list<array{text: string, type: string}>>  $answers  personal data the model finds in each chunk
     */
    private function fakePersonalData(array $answers): void
    {
        $this->personalDataLlm = new FakeAIProvider(...array_map(
            fn (array $fragments): AssistantMessage => new AssistantMessage(json_encode(['fragments' => $fragments], JSON_UNESCAPED_UNICODE)),
            $answers,
        ));
        $this->app->bind(PersonalDataDetector::class, fn (): PersonalDataDetector => (new PersonalDataDetector)->setAiProvider($this->personalDataLlm));
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
