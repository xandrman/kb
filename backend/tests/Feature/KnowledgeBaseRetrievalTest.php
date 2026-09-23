<?php

namespace Tests\Feature;

use App\Enums\AccessLevel;
use App\Neuron\Retrieval\KnowledgeBaseRetrieval;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\RAG\Document as Chunk;
use NeuronAI\RAG\Embeddings\AbstractEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorStore\QdrantVectorStore;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

class KnowledgeBaseRetrievalTest extends TestCase
{
    public const array QUESTION_VECTOR = [0.6, 0.8];

    /**
     * @var list<array{request: RequestInterface}>
     */
    private array $qdrantRequests = [];

    /**
     * @var list<string>
     */
    private array $embeddedTexts = [];

    protected function setUp(): void
    {
        parent::setUp();

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
