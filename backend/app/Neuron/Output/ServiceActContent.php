<?php

namespace App\Neuron\Output;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\NotBlank;

class ServiceActContent
{
    #[SchemaProperty(description: 'Наименование товара для строки «Товар»: вид устройства по-русски и модель дословно («Источник бесперебойного питания SNR-UPS-LID-1500», «Коммутатор SNR-S2950-24G»), не одна модель.', required: true)]
    #[NotBlank]
    public string $productName;

    #[SchemaProperty(description: 'Неисправность со слов потребителя: одно короткое предложение разговорным языком клиента, до 15 слов.', required: true)]
    #[NotBlank]
    public string $complaint;

    #[SchemaProperty(description: 'Описание работ: что сервисный центр проверил, заменил или настроил; одно–два коротких предложения, до 30 слов.', required: true)]
    #[NotBlank]
    public string $workDescription;

    #[SchemaProperty(description: 'Результат выполненных работ: одна фраза до 5 слов («Отремонтирован», «Неисправность не подтверждена», «Отказано в гарантийном ремонте»).', required: true)]
    #[NotBlank]
    public string $result;
}
