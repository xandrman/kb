<?php

namespace Tests\Feature;

use App\Enums\AccessLevel;
use App\Mcp\Servers\McpServer;
use App\Mcp\Tools\AskKnowledgeBaseTool;
use App\Models\Role;
use App\Models\User;
use App\Neuron\InjectionDetector;
use App\Neuron\KnowledgeBaseRag;
use App\Neuron\PersonalDataDetector;
use App\Observability\NeuronTracingObserver;
use App\Observability\Tracing;
use ArrayObject;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Observability\EventBus;
use NeuronAI\RAG\Embeddings\AbstractEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\GraphStore\GraphStoreInterface;
use NeuronAI\RAG\PostProcessor\FixedThresholdPostProcessor;
use NeuronAI\RAG\VectorStore\QdrantVectorStore;
use NeuronAI\Testing\FakeAIProvider;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Psr\Http\Message\RequestInterface;
use Tests\Fakes\FakeGraphStore;
use Tests\TestCase;

class AskKnowledgeBaseToolTest extends TestCase
{
    use RefreshDatabase;

    private FakeGraphStore $graphStore;

    private FakeAIProvider $llm;

    /**
     * @var list<array{request: RequestInterface}>
     */
    private array $qdrantRequests = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->personalDataFound([]);
        $this->injectionVerdict('none');

        $this->app->instance(EmbeddingsProviderInterface::class, new class extends AbstractEmbeddingsProvider
        {
            public function embedText(string $text): array
            {
                return [0.6, 0.8];
            }
        });

        $this->graphStore = new FakeGraphStore(fn (string $query): array => str_contains($query, 'entity.name AS name, degree')
            ? [['name' => 'полосы на экране', 'degree' => 2], ['name' => 'частота обновления', 'degree' => 3]]
            : []);
        $this->app->instance(GraphStoreInterface::class, $this->graphStore);

        $this->llm = new FakeAIProvider(new AssistantMessage('Измените частоту обновления экрана.'));
        $this->app->bind(KnowledgeBaseRag::class, function ($app, array $parameters): KnowledgeBaseRag {
            $rag = new KnowledgeBaseRag($parameters['clearance']);
            $rag->setAiProvider($this->llm);
            // Реранкер заменён порогом: оценка чанка задана ответом Qdrant
            $rag->setPostProcessors([new FixedThresholdPostProcessor(0.1)]);

            return $rag;
        });
    }

    public function test_the_answer_comes_after_progress_notifications_about_each_step(): void
    {
        $this->fakeQdrantFinds('На экране видны полосы: измените частоту обновления экрана.');

        McpServer::actingAs(User::factory()->create(), 'mcp')
            ->tool(AskKnowledgeBaseTool::class, ['question' => 'Что делать, если на экране полосы?'])
            ->assertOk()
            ->assertNotificationCount(4)
            ->assertSentNotification('notifications/progress', ['progressToken' => null, 'progress' => 1, 'message' => 'Ищу в документах фрагменты по вопросу…'])
            ->assertSentNotification('notifications/progress', ['progressToken' => null, 'progress' => 2, 'message' => 'Ищу связи с «полосы на экране», «частота обновления»…'])
            ->assertSentNotification('notifications/progress', ['progressToken' => null, 'progress' => 3, 'message' => 'Проверяю, какие фрагменты отвечают на вопрос (найдено: 1)…'])
            ->assertSentNotification('notifications/progress', ['progressToken' => null, 'progress' => 4, 'message' => 'Формирую ответ…'])
            ->assertSee('Измените частоту обновления экрана.');
    }

    public function test_progress_names_only_entities_the_user_is_cleared_for(): void
    {
        Role::findOrCreate('engineer', 'web')->update(['access_level' => AccessLevel::Internal]);
        $this->fakeQdrantFinds('На экране видны полосы.');

        McpServer::actingAs(User::factory()->create()->assignRole('engineer'), 'mcp')
            ->tool(AskKnowledgeBaseTool::class, ['question' => 'Что делать, если на экране полосы?'])
            ->assertOk();

        // Имена для прогресса и связи графа берутся в пределах допуска пользователя, как и сами фрагменты
        $this->assertSame(AccessLevel::Internal->rank(), $this->graphStore->parametersOf('entity.name AS name, degree')[0]['rank']);
        $this->assertSame(AccessLevel::Internal->rank(), $this->graphStore->parametersOf('MATCH (seed:Chunk)')[0]['rank']);
        $search = json_decode((string) $this->qdrantRequests[1]['request']->getBody(), true);
        $this->assertSame(['public', 'internal'], $search['filter']['must'][0]['match']['any']);
    }

    public function test_nothing_found_gives_a_refusal_without_naming_anything(): void
    {
        $this->fakeQdrantFinds(null);

        McpServer::actingAs(User::factory()->create(), 'mcp')
            ->tool(AskKnowledgeBaseTool::class, ['question' => 'Как испечь пирог?'])
            ->assertOk()
            ->assertNotificationCount(1)
            ->assertSee('Нет данных в базе знаний.');

        $this->assertSame([], $this->graphStore->parametersOf('MATCH (seed:Chunk)'));
        $this->llm->assertNothingSent();
    }

    public function test_time_to_first_token_ends_when_the_model_starts_the_answer(): void
    {
        $spans = $this->recordSpans();
        $this->fakeQdrantFinds('На экране видны полосы: измените частоту обновления экрана.');

        McpServer::actingAs(User::factory()->create(), 'mcp')
            ->tool(AskKnowledgeBaseTool::class, ['question' => 'Что делать, если на экране полосы?'])
            ->assertOk();

        $firstToken = $this->spanNamed($spans, 'mcp.ttft');
        $this->assertSame($this->spanNamed($spans, 'mcp.tool search')->getSpanId(), $firstToken->getParentSpanId());
        $this->assertLessThanOrEqual($this->spanNamed($spans, 'agent KnowledgeBaseRag', 'llm.inference')->getStartEpochNanos(), $firstToken->getEndEpochNanos());
    }

    public function test_a_refusal_without_the_model_is_itself_the_first_token(): void
    {
        $spans = $this->recordSpans();
        $this->fakeQdrantFinds(null);

        McpServer::actingAs(User::factory()->create(), 'mcp')
            ->tool(AskKnowledgeBaseTool::class, ['question' => 'Как испечь пирог?'])
            ->assertOk();

        $this->assertLessThanOrEqual($this->spanNamed($spans, 'mcp.tool search')->getEndEpochNanos(), $this->spanNamed($spans, 'mcp.ttft')->getEndEpochNanos());
        $this->assertGreaterThanOrEqual($this->spanNamed($spans, 'agent KnowledgeBaseRag')->getEndEpochNanos(), $this->spanNamed($spans, 'mcp.ttft')->getEndEpochNanos());
    }

    public function test_a_question_is_required(): void
    {
        McpServer::actingAs(User::factory()->create(), 'mcp')
            ->tool(AskKnowledgeBaseTool::class, [])
            ->assertHasErrors();
    }

    private function fakeQdrantFinds(?string $content): void
    {
        $points = $content === null ? [] : [[
            'id' => 'seed-chunk',
            'score' => 0.7,
            'vector' => [0.6, 0.8],
            'payload' => ['content' => $content, 'sourceType' => 'document', 'sourceName' => '8', 'document_id' => 8, 'access_level' => 'public'],
        ]];

        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(['result' => ['exists' => true]])),
            new Response(200, [], json_encode(['result' => ['points' => $points]])),
        ]));
        $stack->push(Middleware::history($this->qdrantRequests));

        $this->app->instance(QdrantVectorStore::class, new QdrantVectorStore(
            collectionUrl: 'http://qdrant.test/collections/chunks/',
            httpClient: new GuzzleHttpClient(handler: $stack),
        ));
    }

    /**
     * @param  list<array{text: string, type: string}>  $fragments  personal data the model finds in the question
     */
    private function personalDataFound(array $fragments): void
    {
        $this->app->bind(PersonalDataDetector::class, fn (): PersonalDataDetector => (new PersonalDataDetector)->setAiProvider(
            new FakeAIProvider(new AssistantMessage(json_encode(['fragments' => $fragments], JSON_UNESCAPED_UNICODE))),
        ));
    }

    private function injectionVerdict(string $category): void
    {
        $this->app->bind(InjectionDetector::class, fn (): InjectionDetector => (new InjectionDetector)->setAiProvider(
            new FakeAIProvider(new AssistantMessage(json_encode(['category' => $category]))),
        ));
    }

    /**
     * Spans go to memory instead of kb-alloy; tracing services built at boot are rebuilt around the new provider.
     *
     * @return ArrayObject<int, ImmutableSpan>
     */
    private function recordSpans(): ArrayObject
    {
        $spans = new ArrayObject;
        $this->app->instance(TracerProviderInterface::class, new TracerProvider(new SimpleSpanProcessor(new InMemoryExporter($spans))));
        $this->app->forgetInstance(Tracing::class);
        $this->app->forgetInstance(NeuronTracingObserver::class);
        EventBus::clear();
        EventBus::setDefaultObserver(app(NeuronTracingObserver::class));

        return $spans;
    }

    /**
     * The span with the name; with $child — that span's child of the name $child.
     *
     * @param  ArrayObject<int, ImmutableSpan>  $spans
     */
    private function spanNamed(ArrayObject $spans, string $name, ?string $child = null): ImmutableSpan
    {
        foreach ($spans as $span) {
            if ($span->getName() === $name) {
                if ($child === null) {
                    return $span;
                }

                foreach ($spans as $candidate) {
                    if ($candidate->getName() === $child && $candidate->getParentSpanId() === $span->getSpanId()) {
                        return $candidate;
                    }
                }
            }
        }

        $this->fail("Спан {$name} не записан");
    }
}
