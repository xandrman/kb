<?php

namespace App\Actions;

use App\Enums\EntityType;
use App\Neuron\GraphExtractor;
use App\Neuron\Output\ExtractedEntity;
use App\Neuron\Output\ExtractedGraph;
use App\Neuron\Output\ExtractedRelation;
use NeuronAI\Chat\Messages\UserMessage;

class ExtractChunkGraph
{
    /**
     * Ask the LLM for entities and relations of one chunk and keep only a consistent graph (FR-4).
     */
    public function handle(string $text, string $documentName): ExtractedGraph
    {
        // Название документа — единственный источник модели изделия, когда фрагмент называет его общим словом
        $message = new UserMessage("Документ: {$documentName}\n\nФрагмент:\n{$text}");

        // Агент копит историю диалога: на каждый чанк — новый экземпляр
        /** @var ExtractedGraph $extracted */
        $extracted = app(GraphExtractor::class)->structured($message, ExtractedGraph::class, maxRetries: 1);

        $entities = $this->uniqueEntities($extracted->entities);

        $graph = new ExtractedGraph;
        $graph->entities = array_values($entities);
        $graph->relations = $this->consistentRelations($extracted->relations, $entities);

        return $graph;
    }

    /**
     * Graph key of an entity: the same name in another case, spelling of «ё» or quoting is the same entity.
     */
    public static function entityKey(EntityType $type, string $name): string
    {
        return $type->value.':'.self::normalizeName($name);
    }

    private static function normalizeName(string $name): string
    {
        $name = str_replace(['ё', '«', '»', '"', '“', '”', '„'], ['е', '', '', '', '', '', ''], mb_strtolower($name));

        // Подчёркивание — из имён файлов («Optix_MAG301RF»): это тот же пробел
        return trim((string) preg_replace('/[\s_]+/u', ' ', $name));
    }

    /**
     * @param  list<ExtractedEntity>  $entities
     * @return array<string, ExtractedEntity>
     */
    private function uniqueEntities(array $entities): array
    {
        $unique = [];

        foreach ($entities as $entity) {
            if (self::normalizeName($entity->name) !== '') {
                $unique[self::entityKey($entity->type, $entity->name)] ??= $entity;
            }
        }

        return $unique;
    }

    /**
     * Drop relations to unknown entities, self-loops, wrong directions and duplicates.
     *
     * @param  list<ExtractedRelation>  $relations
     * @param  array<string, ExtractedEntity>  $entities
     * @return list<ExtractedRelation>
     */
    private function consistentRelations(array $relations, array $entities): array
    {
        $consistent = [];

        foreach ($relations as $relation) {
            // Связь ссылается на имя без типа: одно имя может означать сущности разных типов, берём пару, которую допускает связь
            foreach ($this->entitiesNamed($relation->source, $entities) as $sourceKey => $source) {
                foreach ($this->entitiesNamed($relation->target, $entities) as $targetKey => $target) {
                    if ($sourceKey === $targetKey || ! $relation->type->connects($source->type, $target->type)) {
                        continue;
                    }

                    $canonical = new ExtractedRelation;
                    $canonical->source = $source->name;
                    $canonical->type = $relation->type;
                    $canonical->target = $target->name;
                    $consistent["{$sourceKey}|{$relation->type->value}|{$targetKey}"] = $canonical;
                }
            }
        }

        return array_values($consistent);
    }

    /**
     * @param  array<string, ExtractedEntity>  $entities
     * @return array<string, ExtractedEntity>
     */
    private function entitiesNamed(string $name, array $entities): array
    {
        $normalized = self::normalizeName($name);

        return array_filter($entities, fn (ExtractedEntity $entity): bool => self::normalizeName($entity->name) === $normalized);
    }
}
