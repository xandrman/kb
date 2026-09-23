<?php

namespace App\Neuron\Output;

use App\Enums\InjectionCategory;
use NeuronAI\StructuredOutput\SchemaProperty;

class InjectionVerdict
{
    #[SchemaProperty(description: 'Категория вопроса: none — обычный вопрос, иначе вид атаки.', required: true)]
    public InjectionCategory $category;
}
