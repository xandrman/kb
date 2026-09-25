<?php

namespace App\Providers;

use App\Actions\AuthenticateWithKeycloakBearer;
use App\Actions\MergeEntitySynonyms;
use App\Contracts\DocumentStorage;
use App\Http\Middleware\TraceMcpRequest;
use App\Http\Responses\KeycloakLogoutResponse;
use App\Models\User;
use App\Neuron\PostProcessor\RerankerPostProcessor;
use App\Observability\NeuronTracingObserver;
use App\Observability\TraceHttpRequests;
use App\Observability\Tracing;
use App\Services\LocalDocumentStorage;
use App\Socialite\KeycloakProvider;
use Filament\Auth\Http\Responses\Contracts\LogoutResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use NeuronAI\Observability\EventBus;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Embeddings\OpenAILikeEmbeddings;
use NeuronAI\RAG\GraphStore\GraphStoreInterface;
use NeuronAI\RAG\GraphStore\Neo4jGraphStore;
use NeuronAI\RAG\VectorStore\QdrantVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LogoutResponse::class, KeycloakLogoutResponse::class);

        $this->app->singleton(TracerProviderInterface::class, fn (): TracerProviderInterface => $this->tracerProvider());
        $this->app->singleton(Tracing::class);
        $this->app->singleton(NeuronTracingObserver::class);
        $this->app->singleton(TraceMcpRequest::class);

        $this->app->bind(DocumentStorage::class, fn (): LocalDocumentStorage => new LocalDocumentStorage(Storage::disk('documents')));

        // Без dimensions: модель не матрёшечная, vLLM отклоняет параметр (ADR-0010)
        $this->app->bind(EmbeddingsProviderInterface::class, fn (): OpenAILikeEmbeddings => new OpenAILikeEmbeddings(
            baseUri: config('services.embedding.url'),
            key: '',
            model: config('services.embedding.model'),
            dimensions: null,
            httpClient: TraceHttpRequests::neuronClient(),
        ));

        // Конструктор обращается к Qdrant и создаёт коллекцию, если её нет, — поэтому только ленивое разрешение
        $this->app->bind(QdrantVectorStore::class, fn (): QdrantVectorStore => new QdrantVectorStore(
            collectionUrl: rtrim(config('services.qdrant.url'), '/').'/collections/'.config('services.qdrant.collection').'/',
            key: config('services.qdrant.key'),
            topK: config('services.qdrant.search_limit'),
            dimension: config('services.qdrant.dimension'),
            httpClient: TraceHttpRequests::neuronClient(),
        ));
        $this->app->bind(VectorStoreInterface::class, QdrantVectorStore::class);

        // Имена сущностей — своя коллекция: соседей ищем среди десятка ближайших, отбор по типу и порогу делает MergeEntitySynonyms
        $this->app->when(MergeEntitySynonyms::class)->needs(VectorStoreInterface::class)->give(fn (): QdrantVectorStore => new QdrantVectorStore(
            collectionUrl: rtrim(config('services.qdrant.url'), '/').'/collections/'.config('services.qdrant.entity_collection').'/',
            key: config('services.qdrant.key'),
            topK: 10,
            dimension: config('services.qdrant.dimension'),
            httpClient: TraceHttpRequests::neuronClient(),
        ));

        $this->app->bind(RerankerPostProcessor::class, fn (): RerankerPostProcessor => new RerankerPostProcessor(
            key: '',
            model: config('services.reranker.model'),
            topN: config('services.reranker.top_n'),
            host: config('services.reranker.url'),
            httpClient: TraceHttpRequests::neuronClient(),
        ));

        $this->app->bind(GraphStoreInterface::class, fn (): Neo4jGraphStore => new Neo4jGraphStore(
            uri: config('services.neo4j.uri'),
            username: config('services.neo4j.username'),
            password: config('services.neo4j.password'),
        ));
    }

    /**
     * FR-9: spans go to kb-alloy in batches; without an endpoint tracing is off and costs nothing.
     */
    private function tracerProvider(): TracerProviderInterface
    {
        $endpoint = config('services.otel.traces_endpoint');

        if (blank($endpoint)) {
            return new NoopTracerProvider;
        }

        $exporter = new SpanExporter((new OtlpHttpTransportFactory)->create($endpoint, ContentTypes::PROTOBUF));
        $resource = ResourceInfoFactory::emptyResource()->merge(ResourceInfo::create(Attributes::create([
            'service.name' => config('services.otel.service_name'),
        ])));
        $provider = new TracerProvider(new BatchSpanProcessor($exporter, Clock::getDefault()), resource: $resource);

        // Спаны уходят после ответа клиенту: PHP-FPM отдаёт ответ до terminate
        $this->app->terminating(fn () => $provider->forceFlush());

        return $provider;
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ADR-0022: вместо Inspector (SaaS), которого Neuron подключает по умолчанию, — спаны OpenTelemetry внутри периметра
        EventBus::setDefaultObserver($this->app->make(NeuronTracingObserver::class));

        // Ни одна задача очереди не наследует родителя спана от предыдущей, даже если конвейер не убрал за собой
        Queue::looping(fn () => $this->app->make(Tracing::class)->unwindTo(0));

        // FR-9: запросы через Http (docling, чтение точек Qdrant) тоже несут traceparent и дают клиентский спан
        Http::globalMiddleware($this->app->make(TraceHttpRequests::class));

        Event::listen(SocialiteWasCalled::class, function (SocialiteWasCalled $event): void {
            $event->extendSocialite('keycloak', KeycloakProvider::class);
        });

        Auth::viaRequest('keycloak-bearer', fn (Request $request): ?User => $this->app
            ->make(AuthenticateWithKeycloakBearer::class)
            ->handle($request));

        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(60)->by(
            $request->user()?->getAuthIdentifier() ?: $request->ip()
        ));
    }
}
