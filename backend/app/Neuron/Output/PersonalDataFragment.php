<?php

namespace App\Neuron\Output;

use App\Enums\PersonalDataType;
use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\NotBlank;

class PersonalDataFragment
{
    #[SchemaProperty(description: 'Фрагмент ровно так, как он написан в тексте, без изменений.', required: true)]
    #[NotBlank]
    public string $text;

    #[SchemaProperty(description: 'Вид персональных данных.', required: true)]
    public PersonalDataType $type;
}
