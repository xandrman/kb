<?php

namespace Tests\Feature;

use App\Actions\ExtractChunkGraph;
use App\Enums\EntityType;
use App\Enums\RelationType;
use App\Neuron\GraphExtractor;
use App\Neuron\Output\ExtractedEntity;
use App\Neuron\Output\ExtractedGraph;
use App\Neuron\Output\ExtractedRelation;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use Tests\TestCase;

class ChunkGraphExtractionTest extends TestCase
{
    private FakeAIProvider $provider;

    public function test_the_chunk_is_sent_with_the_graph_schema(): void
    {
        $this->fakeLlmAnswer(['entities' => [], 'relations' => []]);

        app(ExtractChunkGraph::class)->handle('Подключите монитор кабелем DisplayPort.', 'Optix_MPG341QR_ru.pdf');

        $this->provider->assertSent(function (RequestRecord $record): bool {
            $entityTypes = $record->structuredSchema['properties']['entities']['items']['properties']['type']['enum'] ?? null;

            return $record->method === 'structured'
                && $record->structuredClass === ExtractedGraph::class
                && $record->messages[0]->getContent() === "Документ: Optix_MPG341QR_ru.pdf\n\nФрагмент:\nПодключите монитор кабелем DisplayPort."
                && $entityTypes === array_column(EntityType::cases(), 'value');
        });
    }

    public function test_entities_differing_only_in_spelling_are_merged(): void
    {
        $this->fakeLlmAnswer([
            'entities' => [
                ['name' => 'Монитор «Optix»', 'type' => 'Equipment'],
                ['name' => 'монитор  optix', 'type' => 'Equipment'],
                ['name' => 'Разъём DisplayPort', 'type' => 'Component'],
                ['name' => 'разъем displayport', 'type' => 'Component'],
            ],
            'relations' => [
                ['source' => 'Монитор «Optix»', 'type' => 'HAS_COMPONENT', 'target' => 'Разъём DisplayPort'],
                ['source' => 'монитор  optix', 'type' => 'HAS_COMPONENT', 'target' => 'разъем displayport'],
            ],
        ]);

        $graph = app(ExtractChunkGraph::class)->handle('фрагмент', 'manual.pdf');

        $this->assertSame([
            ['Монитор «Optix»', EntityType::Equipment],
            ['Разъём DisplayPort', EntityType::Component],
        ], $this->entities($graph));
        $this->assertSame([['Монитор «Optix»', RelationType::HasComponent, 'Разъём DisplayPort']], $this->relations($graph));
        $this->assertSame('Equipment:монитор optix', ExtractChunkGraph::entityKey(EntityType::Equipment, 'Монитор «Optix»'));
        $this->assertSame(
            ExtractChunkGraph::entityKey(EntityType::Equipment, 'Optix MAG301RF'),
            ExtractChunkGraph::entityKey(EntityType::Equipment, 'Optix_MAG301RF'),
        );
    }

    public function test_relations_to_unknown_entities_self_loops_and_wrong_directions_are_dropped(): void
    {
        $this->fakeLlmAnswer([
            'entities' => [
                ['name' => 'Нет изображения', 'type' => 'Fault'],
                ['name' => 'Проверка кабеля', 'type' => 'Procedure'],
            ],
            'relations' => [
                ['source' => 'Нет изображения', 'type' => 'FIXED_BY', 'target' => 'Проверка кабеля'],
                ['source' => 'Проверка кабеля', 'type' => 'FIXED_BY', 'target' => 'Нет изображения'],
                ['source' => 'Нет изображения', 'type' => 'FIXED_BY', 'target' => 'Замена матрицы'],
                ['source' => 'Нет изображения', 'type' => 'FIXED_BY', 'target' => 'Нет изображения'],
            ],
        ]);

        $graph = app(ExtractChunkGraph::class)->handle('фрагмент', 'manual.pdf');

        $this->assertSame([['Нет изображения', RelationType::FixedBy, 'Проверка кабеля']], $this->relations($graph));
    }

    public function test_a_chunk_without_entities_gives_an_empty_graph(): void
    {
        $this->fakeLlmAnswer(['entities' => [], 'relations' => []]);

        $graph = app(ExtractChunkGraph::class)->handle('Содержание ........ 3', 'manual.pdf');

        $this->assertSame([], $graph->entities);
        $this->assertSame([], $graph->relations);
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private function fakeLlmAnswer(array $answer): void
    {
        $this->provider = new FakeAIProvider(new AssistantMessage(json_encode($answer, JSON_UNESCAPED_UNICODE)));
        $this->app->bind(GraphExtractor::class, fn (): GraphExtractor => (new GraphExtractor)->setAiProvider($this->provider));
    }

    /**
     * @return list<array{0: string, 1: EntityType}>
     */
    private function entities(ExtractedGraph $graph): array
    {
        return array_map(fn (ExtractedEntity $entity): array => [$entity->name, $entity->type], $graph->entities);
    }

    /**
     * @return list<array{0: string, 1: RelationType, 2: string}>
     */
    private function relations(ExtractedGraph $graph): array
    {
        return array_map(fn (ExtractedRelation $relation): array => [$relation->source, $relation->type, $relation->target], $graph->relations);
    }
}
