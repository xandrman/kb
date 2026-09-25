<?php

namespace App\Neuron\Output;

use NeuronAI\StructuredOutput\SchemaProperty;

class SynonymVerdict
{
    #[SchemaProperty(description: 'true, если оба имени обозначают один и тот же объект реального мира.', required: true)]
    public bool $same;
}
