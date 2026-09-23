<?php

namespace App\Neuron\Output;

use NeuronAI\StructuredOutput\SchemaProperty;

/**
 * Entities and relations the LLM found in one chunk (FR-4).
 */
class ExtractedGraph
{
    /**
     * @var list<ExtractedEntity>
     */
    #[SchemaProperty(description: 'Сущности, упомянутые во фрагменте.', required: true, anyOf: [ExtractedEntity::class])]
    public array $entities = [];

    /**
     * @var list<ExtractedRelation>
     */
    #[SchemaProperty(description: 'Связи между сущностями из списка entities, прямо указанные во фрагменте.', required: true, anyOf: [ExtractedRelation::class])]
    public array $relations = [];
}
