<?php

namespace App\Neuron\Output;

use App\Enums\EntityType;
use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\NotBlank;

class ExtractedEntity
{
    #[SchemaProperty(description: 'Имя сущности как в тексте, на языке оригинала; модели техники — дословно, с производителем, если он указан.', required: true)]
    #[NotBlank]
    public string $name;

    #[SchemaProperty(description: 'Тип сущности.', required: true)]
    public EntityType $type;
}
