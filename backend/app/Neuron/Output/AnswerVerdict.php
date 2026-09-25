<?php

namespace App\Neuron\Output;

use NeuronAI\StructuredOutput\SchemaProperty;

/**
 * Поле с неподтверждёнными утверждениями — первое: модель сначала выписывает их, потом ставит оценки.
 */
class AnswerVerdict
{
    #[SchemaProperty(description: 'Утверждения ответа, которых нет во фрагментах, кратко через точку с запятой; пустая строка, если таких нет.', required: true)]
    public string $unsupported;

    #[SchemaProperty(description: 'Опора на фрагменты от 1 до 5.', required: true)]
    public int $faithfulness;

    #[SchemaProperty(description: 'Полнота от 1 до 5.', required: true)]
    public int $completeness;

    #[SchemaProperty(description: 'Называет ли ответ документ-источник.', required: true)]
    public bool $citesSources;
}
