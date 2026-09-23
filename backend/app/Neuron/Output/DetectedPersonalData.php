<?php

namespace App\Neuron\Output;

use NeuronAI\StructuredOutput\SchemaProperty;

/**
 * Personal data of individuals the LLM found in one chunk.
 */
class DetectedPersonalData
{
    /**
     * @var list<PersonalDataFragment>
     */
    #[SchemaProperty(description: 'Персональные данные физических лиц во фрагменте.', required: true, anyOf: [PersonalDataFragment::class])]
    public array $fragments = [];
}
