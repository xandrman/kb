<?php

namespace Tests\Feature;

use App\Actions\MergeEntitySynonyms;
use App\Neuron\SynonymJudge;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\RAG\Embeddings\AbstractEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\GraphStore\GraphStoreInterface;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use Tests\Fakes\FakeGraphStore;
use Tests\TestCase;

class GraphSynonymMergeTest extends TestCase
{
    /**
     * Name vectors: close directions stand for names the embedding model finds similar.
     *
     * @var array<string, list<float>>
     */
    public const array VECTORS = [
        'Micro-Star Int\'l Co., Ltd.' => [1.0, 0.0, 0.0],
        'MICRO-STAR INTERNATIONAL CO., LTD.' => [0.95, 0.312, 0.0],
        'MSI' => [0.5, 0.0, 0.866],
        'HDMI1' => [0.0, 1.0, 0.0],
        'HDMI2' => [0.0, 0.95, 0.312],
        'кнопка Вверх' => [0.0, 0.0, 1.0],
        'кнопка Вниз' => [0.312, 0.0, 0.95],
    ];

    private FakeGraphStore $graphStore;

    private FakeAIProvider $llm;

    /**
     * @var list<array{key: string, name: string, type: string, mentions: int}>
     */
    private array $entities = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->graphStore = new FakeGraphStore(fn (string $query): array => str_contains($query, 'RETURN entity.key AS key, entity.name AS name')
            ? $this->entities
            : []);
        $this->app->instance(GraphStoreInterface::class, $this->graphStore);

        $this->app->instance(EmbeddingsProviderInterface::class, new class extends AbstractEmbeddingsProvider
        {
            public function embedText(string $text): array
            {
                return GraphSynonymMergeTest::VECTORS[$text];
            }
        });

        $this->app->when(MergeEntitySynonyms::class)->needs(VectorStoreInterface::class)->give(fn (): MemoryVectorStore => new MemoryVectorStore(topK: 10));
    }

    public function test_similar_names_confirmed_by_the_llm_are_merged_into_the_most_mentioned_one(): void
    {
        $this->givenEntities([
            ['Organization', 'Micro-Star Int\'l Co., Ltd.', 1],
            ['Organization', 'MICRO-STAR INTERNATIONAL CO., LTD.', 3],
        ]);
        $this->fakeVerdicts([true]);

        $merged = app(MergeEntitySynonyms::class)->handle();

        $this->assertSame(1, $merged);
        $this->llm->assertSent(fn (RequestRecord $record): bool => $record->messages[0]->getContent()
            === "Тип: Organization\nСущность 1: Micro-Star Int'l Co., Ltd.\nСущность 2: MICRO-STAR INTERNATIONAL CO., LTD.");
        $this->assertSame(
            ['duplicate' => 'Organization:micro-star int\'l co., ltd.', 'canonical' => 'Organization:micro-star international co., ltd.'],
            $this->graphStore->parametersOf('MERGE (chunk)-[:MENTIONS]->(canonical)')[0],
        );
    }

    public function test_a_pair_rejected_by_the_llm_is_not_merged(): void
    {
        $this->givenEntities([
            ['Component', 'кнопка Вверх', 2],
            ['Component', 'кнопка Вниз', 2],
        ]);
        $this->fakeVerdicts([false]);

        $this->assertSame(0, app(MergeEntitySynonyms::class)->handle());
        $this->assertSame([], $this->graphStore->parametersOf('MERGE (chunk)-[:MENTIONS]->(canonical)'));
    }

    public function test_names_with_different_numbers_never_reach_the_llm(): void
    {
        $this->givenEntities([
            ['Component', 'HDMI1', 2],
            ['Component', 'HDMI2', 2],
        ]);
        $this->fakeVerdicts([]);

        $this->assertSame(0, app(MergeEntitySynonyms::class)->handle());
        $this->llm->assertNothingSent();
    }

    public function test_entities_of_different_types_or_below_the_threshold_are_not_candidates(): void
    {
        $this->givenEntities([
            ['Organization', 'Micro-Star Int\'l Co., Ltd.', 1],
            ['Component', 'MICRO-STAR INTERNATIONAL CO., LTD.', 1],
            ['Organization', 'MSI', 1],
            ['Component', 'кнопка Вверх', 1],
        ]);
        $this->fakeVerdicts([]);

        $this->assertSame(0, app(MergeEntitySynonyms::class)->handle());
        $this->llm->assertNothingSent();
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int}>  $entities
     */
    private function givenEntities(array $entities): void
    {
        $this->entities = array_map(fn (array $entity): array => [
            'key' => $entity[0].':'.mb_strtolower($entity[1]),
            'name' => $entity[1],
            'type' => $entity[0],
            'mentions' => $entity[2],
        ], $entities);
    }

    /**
     * @param  list<bool>  $verdicts
     */
    private function fakeVerdicts(array $verdicts): void
    {
        $this->llm = new FakeAIProvider(...array_map(
            fn (bool $same): AssistantMessage => new AssistantMessage(json_encode(['same' => $same])),
            $verdicts,
        ));
        $this->app->bind(SynonymJudge::class, fn (): SynonymJudge => (new SynonymJudge)->setAiProvider($this->llm));
    }
}
