<?php

namespace App\Neuron\Output;

use NeuronAI\StructuredOutput\SchemaProperty;

/**
 * Обоснование — первое: модель сначала выписывает сведения и их изделие, потом решает.
 */
class RefusalVerdict
{
    #[SchemaProperty(description: 'Сведения из фрагментов, отвечающие на вопрос, и к какому изделию они относятся.', required: true)]
    public string $evidence;

    #[SchemaProperty(description: 'Был ли отказ ошибкой.', required: true)]
    public bool $answerInFragments;
}
