<?php

namespace App\Neuron;

use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAILike;

/**
 * Extracts knowledge graph entities and relations from one chunk with the local LLM (FR-4, ADR-0009, ADR-0022).
 */
class GraphExtractor extends Agent
{
    protected function provider(): AIProviderInterface
    {
        // Извлечение должно быть воспроизводимым: без сэмплирования
        return new OpenAILike(
            baseUri: config('services.llm.url'),
            key: '',
            model: config('services.llm.model'),
            parameters: ['temperature' => 0],
        );
    }

    protected function instructions(): string
    {
        return <<<'PROMPT'
            Ты извлекаешь граф знаний из фрагмента сервисной документации по технике: руководств, актов ремонта, гарантийных талонов, технических паспортов.

            Типы сущностей:
            - Equipment — изделие или модель техники целиком (например, «Optix MPG341QR», «монитор»);
            - Component — узел, деталь, разъём, кабель, элемент управления изделия;
            - Fault — неисправность, симптом, ошибка, код ошибки;
            - Procedure — действие или процедура: установка, настройка, диагностика, ремонт, замена;
            - Organization — производитель, сервисный центр, поставщик.

            Типы связей (направление строго такое):
            - HAS_COMPONENT: Equipment или Component → Component;
            - HAS_FAULT: Equipment или Component → Fault;
            - FIXED_BY: Fault → Procedure;
            - APPLIES_TO: Procedure → Equipment или Component;
            - MANUFACTURED_BY: Equipment или Component → Organization;
            - SERVICED_BY: Equipment → Organization.

            Во входе указано название документа и сам фрагмент.

            Правила:
            - извлекай только то, что прямо сказано во фрагменте, ничего не додумывай;
            - модель изделия называй всегда одинаково: серия и модель без производителя, версии документа, языка и символов подчёркивания (из «Optix_MAG301RFv1.0_Russian.pdf» и «MSI Optix MAG301RF» — «Optix MAG301RF»); производителя выделяй отдельной сущностью Organization;
            - если изделие во фрагменте названо общим словом («монитор», «устройство»), а модель ясна из названия документа, называй его именем модели по правилу выше;
            - имена — в именительном падеже, единственном числе, если в тексте не название во множественном числе;
            - Fault — только неисправности, сбои и их симптомы; функции, режимы и настройки изделия — не Fault, а Component;
            - людей, их имена, телефоны, адреса и другие персональные данные не извлекай;
            - имена сущностей пиши на языке фрагмента, не переводи; модели и артикулы — дословно;
            - одна сущность — одно имя: не дублируй её под разными формулировками;
            - в связях source и target — точно имена из списка entities;
            - если во фрагменте нет сущностей или связей — верни пустые списки.
            PROMPT;
    }
}
