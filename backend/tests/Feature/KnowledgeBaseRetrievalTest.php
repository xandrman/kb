<?php

namespace Tests\Feature;

use App\Enums\AccessLevel;
use App\Models\Document;
use App\Neuron\Retrieval\KnowledgeBaseRetrieval;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\RAG\Document as Chunk;
use NeuronAI\RAG\Embeddings\AbstractEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\GraphStore\GraphStoreInterface;
use NeuronAI\RAG\VectorStore\QdrantVectorStore;
use Psr\Http\Message\RequestInterface;
use Tests\Fakes\FakeGraphStore;
use Tests\TestCase;

class KnowledgeBaseRetrievalTest extends TestCase
{
    use RefreshDatabase;

    public const array QUESTION_VECTOR = [0.6, 0.8];

    /**
     * @var list<array{request: RequestInterface}>
     */
    private array $qdrantRequests = [];

    /**
     * @var list<string>
     */
    private array $embeddedTexts = [];

    private FakeGraphStore $graphStore;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.qdrant.url' => 'http://qdrant.test', 'services.qdrant.collection' => 'chunks']);

        $this->graphStore = new FakeGraphStore;
        $this->app->instance(GraphStoreInterface::class, $this->graphStore);

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

                return KnowledgeBaseRetrievalTest::QUESTION_VECTOR;
            }
        });
    }

    public function test_the_question_is_embedded_with_the_retrieval_instruction(): void
    {
        $this->fakeQdrant([]);

        $this->retrieve('Что делать, если на экране полосы?', AccessLevel::Public);

        $this->assertSame(
            ["Instruct: Given a question, retrieve passages that answer the question\nQuery: Что делать, если на экране полосы?"],
            $this->embeddedTexts,
        );
    }

    public function test_the_clearance_filters_inside_the_qdrant_search(): void
    {
        $this->fakeQdrant([]);

        $this->retrieve('Что делать, если на экране полосы?', AccessLevel::Internal);

        $search = $this->qdrantRequests[1]['request'];
        $this->assertSame('http://qdrant.test/collections/chunks/points/query', (string) $search->getUri());

        $body = json_decode((string) $search->getBody(), true);
        $this->assertSame(self::QUESTION_VECTOR, $body['query']['recommend']['positive'][0]);
        $this->assertSame(['must' => [['key' => 'access_level', 'match' => ['any' => ['public', 'internal']]]]], $body['filter']);
        $this->assertSame(10, $body['limit']);
    }

    public function test_a_public_only_user_searches_public_chunks_only(): void
    {
        $this->fakeQdrant([]);

        $this->retrieve('Гарантия', AccessLevel::Public);

        $body = json_decode((string) $this->qdrantRequests[1]['request']->getBody(), true);
        $this->assertSame(['public'], $body['filter']['must'][0]['match']['any']);
    }

    public function test_found_chunks_keep_their_text_score_and_provenance(): void
    {
        $this->fakeQdrant([[
            'id' => '6b1f5a52-3c6e-5f0a-9b7d-2f1c0d4e8a11',
            'score' => 0.71,
            'vector' => self::QUESTION_VECTOR,
            'payload' => [
                'content' => "Устранение неисправностей\nПолосы на экране: обновите драйвер видеокарты.",
                'sourceType' => 'document',
                'sourceName' => '8',
                'document_id' => 8,
                'chunk_index' => 60,
                'page_numbers' => [24],
                'access_level' => 'public',
            ],
        ]]);

        $chunks = $this->retrieve('полосы на экране', AccessLevel::Public);

        $this->assertCount(1, $chunks);
        $this->assertSame('6b1f5a52-3c6e-5f0a-9b7d-2f1c0d4e8a11', $chunks[0]->getId());
        $this->assertSame(0.71, $chunks[0]->getScore());
        $this->assertStringContainsString('обновите драйвер видеокарты', $chunks[0]->getContent());
        $this->assertSame(8, $chunks[0]->metadata['document_id']);
        $this->assertSame([24], $chunks[0]->metadata['page_numbers']);
    }

    public function test_the_graph_adds_chunks_reached_from_the_found_ones_within_the_clearance(): void
    {
        $this->fakeQdrant([$this->point('seed-chunk', 'Полосы на экране: проверьте кабель.')]);
        $this->graphStore->responder = fn (string $query): array => str_contains($query, 'MATCH (seed:Chunk)')
            ? [
                ['chunk_id' => 'fix-chunk', 'hops' => 1, 'paths' => 3, 'facts' => ['полосы на экране —FIXED_BY→ обновление драйвера']],
                ['chunk_id' => 'filtered-chunk', 'hops' => 2, 'paths' => 1, 'facts' => ['монитор —HAS_COMPONENT→ подставка']],
            ]
            : [];
        Http::fake(['qdrant.test/collections/chunks/points/scroll' => Http::response(['result' => ['points' => [
            ['id' => 'fix-chunk', 'payload' => ['content' => 'Обновите драйвер видеокарты.', 'sourceType' => 'document', 'sourceName' => '5', 'document_id' => 5, 'page_numbers' => [15], 'access_level' => 'internal']],
        ]]])]);

        $chunks = $this->retrieve('Что делать, если на экране полосы?', AccessLevel::Internal);

        $this->assertSame(['seed-chunk', 'fix-chunk'], array_map(fn (Chunk $chunk): string => (string) $chunk->getId(), $chunks));
        $this->assertSame('vector', $chunks[0]->metadata['retrieved_by']);
        $this->assertSame('graph', $chunks[1]->metadata['retrieved_by']);
        $this->assertSame(['полосы на экране —FIXED_BY→ обновление драйвера'], $chunks[1]->metadata['graph_facts']);
        $this->assertSame([15], $chunks[1]->metadata['page_numbers']);
        $this->assertSame('Обновите драйвер видеокарты.', $chunks[1]->getContent());

        $graphQuery = $this->graphStore->parametersOf('MATCH (seed:Chunk)')[0];
        $this->assertSame(['seed-chunk'], $graphQuery['seedChunkIds']);
        $this->assertSame(AccessLevel::Internal->rank(), $graphQuery['rank']);

        Http::assertSent(fn (Request $request): bool => $request->data()['filter'] === ['must' => [
            ['has_id' => ['fix-chunk', 'filtered-chunk']],
            ['key' => 'access_level', 'match' => ['any' => ['public', 'internal']]],
        ]]);
    }

    public function test_the_equipment_of_found_chunks_leads_to_the_closest_chunks_of_other_documents_about_it(): void
    {
        // Акт ремонта найден; ответ на вторую часть вопроса — в паспорте той же модели
        $this->fakeQdrant([$this->point('act-chunk', 'АКТ СЕРВИСНОГО ОБСЛУЖИВАНИЯ. Товар: SNR-UPS-BCRM-480-9')]);
        $this->graphStore->responder = fn (string $query): array => str_contains($query, '(equipment:Entity')
            ? [
                ['chunk_id' => 'passport-batteries', 'equipment' => 'SNR-UPS-BCRM-480-9'],
                ['chunk_id' => 'passport-dimensions', 'equipment' => 'SNR-UPS-BCRM-480-9'],
            ]
            : [];
        Http::fake(['qdrant.test/collections/chunks/points/query' => Http::response(['result' => ['points' => [
            ['id' => 'passport-batteries', 'score' => 0.34, 'payload' => ['content' => 'Аккумуляторы 12 В 9 Ач в количестве 40 шт.', 'sourceType' => 'document', 'sourceName' => '19', 'document_id' => 19, 'page_numbers' => [3], 'access_level' => 'public']],
        ]]])]);

        $chunks = $this->retrieve('Что ремонтировали в батарейном блоке и сколько в нём аккумуляторов по паспорту?', AccessLevel::Internal);

        $this->assertSame(['act-chunk', 'passport-batteries'], array_map(fn (Chunk $chunk): string => (string) $chunk->getId(), $chunks));
        $this->assertSame('graph', $chunks[1]->metadata['retrieved_by']);
        $this->assertSame(['SNR-UPS-BCRM-480-9 — та же модель изделия, что в найденных фрагментах'], $chunks[1]->metadata['graph_facts']);
        $this->assertSame(0.34, $chunks[1]->getScore());

        $bridgeQuery = $this->graphStore->parametersOf('(equipment:Entity')[0];
        $this->assertSame(['act-chunk'], $bridgeQuery['seedChunkIds']);
        $this->assertSame('Equipment', $bridgeQuery['equipmentType']);
        $this->assertSame(AccessLevel::Internal->rank(), $bridgeQuery['rank']);

        // Кандидаты упорядочивает вектор вопроса, и Qdrant ещё раз проверяет гриф (FR-7)
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://qdrant.test/collections/chunks/points/query'
            && $request->data()['query'] === self::QUESTION_VECTOR
            && $request->data()['filter'] === ['must' => [
                ['has_id' => ['passport-batteries', 'passport-dimensions']],
                ['key' => 'access_level', 'match' => ['any' => ['public', 'internal']]],
            ]]
            && $request->data()['limit'] === 2);
        $this->assertCount(1, $this->embeddedTexts);
    }

    public function test_each_equipment_gets_its_own_closest_chunks_and_only_the_best_seeds_lead_to_equipment(): void
    {
        $this->fakeQdrant(array_map(fn (int $number): array => $this->point("seed-{$number}", "Фрагмент {$number}"), range(1, 5)));
        $this->graphStore->responder = fn (string $query): array => str_contains($query, '(equipment:Entity')
            ? [
                ['chunk_id' => 'lid-passport', 'equipment' => 'SNR-UPS-LID-1500'],
                ['chunk_id' => 'onrm-passport', 'equipment' => 'SNR-UPS-ONRM-1000-S24'],
            ]
            : [];
        Http::fake(fn (Request $request) => Http::response(['result' => ['points' => [[
            'id' => $request->data()['filter']['must'][0]['has_id'][0],
            'score' => 0.3,
            'payload' => ['content' => 'Паспорт', 'sourceType' => 'document', 'sourceName' => '4', 'document_id' => 4, 'access_level' => 'public'],
        ]]]]));

        $chunks = $this->retrieve('Какое время автономной работы у этих ИБП?', AccessLevel::Public);

        $this->assertSame(['lid-passport', 'onrm-passport'], array_map(fn (Chunk $chunk): string => (string) $chunk->getId(), array_slice($chunks, 5)));
        $this->assertSame(['seed-1', 'seed-2', 'seed-3'], $this->graphStore->parametersOf('(equipment:Entity')[0]['seedChunkIds']);
        Http::assertSentCount(2);
    }

    public function test_identifiers_are_the_tokens_of_the_question_with_a_digit(): void
    {
        $this->assertSame(
            ['СЦ-714029', 'SNR-UPS-BCRM-480-9', 'MAG301RF'],
            KnowledgeBaseRetrieval::identifiers('По акту СЦ-714029 ремонтировали SNR-UPS-BCRM-480-9 на 12 В, а монитор MAG301RF — 2 раза?'),
        );
    }

    public function test_chunks_containing_an_identifier_of_the_question_come_first(): void
    {
        $this->fakeQdrant([$this->point('similar-act', 'АКТ № СЦ-743718'), $this->point('act-chunk', 'АКТ № СЦ-714029')]);
        Http::fake(['qdrant.test/collections/chunks/points/query' => Http::response(['result' => ['points' => [
            ['id' => 'act-chunk', 'score' => 0.32, 'payload' => ['content' => 'АКТ № СЦ-714029', 'sourceType' => 'document', 'sourceName' => '104', 'document_id' => 104, 'access_level' => 'internal']],
        ]]])]);

        $chunks = $this->retrieve('Что ремонтировали по акту СЦ-714029?', AccessLevel::Internal);

        $this->assertSame(['act-chunk', 'similar-act'], array_map(fn (Chunk $chunk): string => (string) $chunk->getId(), $chunks));
        $this->assertSame('identifier', $chunks[0]->metadata['retrieved_by']);
        $this->assertSame(0.32, $chunks[0]->getScore());

        // Подстрока ищется среди того, что открывает допуск, ближайшие к вопросу — первыми (FR-7)
        Http::assertSent(fn (Request $request): bool => $request->data()['query'] === self::QUESTION_VECTOR
            && $request->data()['filter'] === ['must' => [
                ['key' => 'content', 'match' => ['text' => 'СЦ-714029']],
                ['key' => 'access_level', 'match' => ['any' => ['public', 'internal']]],
            ]]
            && $request->data()['limit'] === 2);
        $this->assertCount(1, $this->embeddedTexts);
    }

    public function test_a_chunk_already_reached_by_relations_is_not_bridged_again(): void
    {
        $this->fakeQdrant([$this->point('seed-chunk', 'Полосы на экране: проверьте кабель.')]);
        $this->graphStore->responder = fn (string $query): array => match (true) {
            str_contains($query, 'MATCH (seed:Chunk)') => [['chunk_id' => 'fix-chunk', 'hops' => 1, 'paths' => 1, 'facts' => ['полосы —FIXED_BY→ замена кабеля']]],
            str_contains($query, '(equipment:Entity') => [['chunk_id' => 'fix-chunk', 'equipment' => 'MAG301RF']],
            default => [],
        };
        Http::fake(['qdrant.test/collections/chunks/points/scroll' => Http::response(['result' => ['points' => [
            ['id' => 'fix-chunk', 'payload' => ['content' => 'Замените кабель.', 'sourceType' => 'document', 'sourceName' => '5', 'document_id' => 5, 'access_level' => 'public']],
        ]]])]);

        $chunks = $this->retrieve('полосы на экране', AccessLevel::Public);

        $this->assertSame(['seed-chunk', 'fix-chunk'], array_map(fn (Chunk $chunk): string => (string) $chunk->getId(), $chunks));
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/points/query'));
    }

    public function test_nothing_found_by_vectors_means_no_graph_expansion(): void
    {
        $this->fakeQdrant([]);
        Http::fake();

        $this->assertSame([], $this->retrieve('Как испечь пирог?', AccessLevel::Confidential));
        $this->assertSame([], $this->graphStore->parametersOf('MATCH (seed:Chunk)'));
        Http::assertNothingSent();
    }

    public function test_found_chunks_carry_the_name_and_type_of_their_document(): void
    {
        $document = Document::factory()->create(['original_name' => 'Optix_MAG301RFv1.0_Russian.pdf']);
        $this->fakeQdrant([[...$this->point('seed-chunk', 'Полосы на экране.'), 'payload' => [
            'content' => 'Полосы на экране.', 'sourceType' => 'document', 'sourceName' => (string) $document->id, 'document_id' => $document->id, 'access_level' => 'public',
        ]]]);

        $chunks = $this->retrieve('полосы', AccessLevel::Public);

        $this->assertSame('Optix_MAG301RFv1.0_Russian.pdf', $chunks[0]->metadata['document_name']);
        $this->assertSame($document->documentType->name, $chunks[0]->metadata['document_type']);
    }

    /**
     * @return array<string, mixed>
     */
    private function point(string $id, string $content): array
    {
        return [
            'id' => $id,
            'score' => 0.7,
            'vector' => self::QUESTION_VECTOR,
            'payload' => ['content' => $content, 'sourceType' => 'document', 'sourceName' => '8', 'document_id' => 8, 'access_level' => 'public'],
        ];
    }

    /**
     * @return list<Chunk>
     */
    private function retrieve(string $question, AccessLevel $clearance): array
    {
        return app(KnowledgeBaseRetrieval::class, ['clearance' => $clearance])->retrieve(new UserMessage($question));
    }

    /**
     * @param  list<array<string, mixed>>  $points
     */
    private function fakeQdrant(array $points): void
    {
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(['result' => ['exists' => true]])),
            new Response(200, [], json_encode(['result' => ['points' => $points]])),
        ]));
        $stack->push(Middleware::history($this->qdrantRequests));

        $this->app->instance(QdrantVectorStore::class, new QdrantVectorStore(
            collectionUrl: 'http://qdrant.test/collections/chunks/',
            topK: 10,
            httpClient: new GuzzleHttpClient(handler: $stack),
        ));
    }
}
