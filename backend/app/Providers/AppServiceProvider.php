<?php

namespace App\Providers;

use App\Actions\AuthenticateWithKeycloakBearer;
use App\Contracts\DocumentStorage;
use App\Http\Responses\KeycloakLogoutResponse;
use App\Models\User;
use App\Services\LocalDocumentStorage;
use App\Socialite\KeycloakProvider;
use Filament\Auth\Http\Responses\Contracts\LogoutResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Embeddings\OpenAILikeEmbeddings;
use NeuronAI\RAG\GraphStore\GraphStoreInterface;
use NeuronAI\RAG\GraphStore\Neo4jGraphStore;
use NeuronAI\RAG\VectorStore\QdrantVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LogoutResponse::class, KeycloakLogoutResponse::class);

        $this->app->bind(DocumentStorage::class, fn (): LocalDocumentStorage => new LocalDocumentStorage(Storage::disk('documents')));

        // Без dimensions: модель не матрёшечная, vLLM отклоняет параметр (ADR-0010)
        $this->app->bind(EmbeddingsProviderInterface::class, fn (): OpenAILikeEmbeddings => new OpenAILikeEmbeddings(
            baseUri: config('services.embedding.url'),
            key: '',
            model: config('services.embedding.model'),
            dimensions: null,
        ));

        // Конструктор обращается к Qdrant и создаёт коллекцию, если её нет, — поэтому только ленивое разрешение
        $this->app->bind(VectorStoreInterface::class, fn (): QdrantVectorStore => new QdrantVectorStore(
            collectionUrl: rtrim(config('services.qdrant.url'), '/').'/collections/'.config('services.qdrant.collection').'/',
            key: config('services.qdrant.key'),
            dimension: config('services.qdrant.dimension'),
        ));

        $this->app->bind(GraphStoreInterface::class, fn (): Neo4jGraphStore => new Neo4jGraphStore(
            uri: config('services.neo4j.uri'),
            username: config('services.neo4j.username'),
            password: config('services.neo4j.password'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
