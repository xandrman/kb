<?php

namespace App\Neuron\Output;

use App\Enums\RelationType;
use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\NotBlank;

class ExtractedRelation
{
    #[SchemaProperty(description: 'Имя исходной сущности, точно как в списке entities.', required: true)]
    #[NotBlank]
    public string $source;

    #[SchemaProperty(description: 'Тип связи.', required: true)]
    public RelationType $type;

    #[SchemaProperty(description: 'Имя целевой сущности, точно как в списке entities.', required: true)]
    #[NotBlank]
    public string $target;
}
