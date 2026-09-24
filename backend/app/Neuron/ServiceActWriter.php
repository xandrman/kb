<?php

namespace App\Neuron;

use App\Observability\TraceHttpRequests;
use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAILike;

/**
 * Writes the repair story of a synthetic service act for the pilot corpus (SRS 4.5, component 1).
 */
class ServiceActWriter extends Agent
{
    protected function provider(): AIProviderInterface
    {
        return new OpenAILike(
            baseUri: config('services.llm.url'),
            key: '',
            model: config('services.llm.model'),
            parameters: ['temperature' => 0.9],
            httpClient: TraceHttpRequests::neuronClient(),
        );
    }

    protected function instructions(): string
    {
        return <<<'PROMPT'
            Ты заполняешь акт сервисного обслуживания сервисного центра, который ремонтирует ИБП, батареи, инверторы,
            блоки распределения питания, коммутаторы и оборудование мониторинга SNR.
            Даны модель устройства и тип обслуживания. Придумай правдоподобный случай для этой модели:
            жалобу клиента, выполненные работы и результат, согласованные между собой и с типом обслуживания.

            Акт — бланк с узкими графами, пиши кратко, как пишут в бланках:
            - товар — вид устройства по-русски и модель дословно: «Инвертор SNR-INV-2000-5000-SH»;
            - жалоба — одно предложение словами клиента, до 15 слов, без приветствий и технических терминов, которых клиент не знает;
            - описание работ — одно-два предложения языком сервисного инженера, до 30 слов: диагностика, замена конкретного узла
              (АКБ, вентилятор, плата, предохранитель), обновление прошивки, калибровка;
            - результат — до 5 слов: «Отремонтирован», «Неисправность не подтверждена», «Отказано в гарантийном ремонте».
            Не упоминай людей, телефоны, адреса и организации. Пиши по-русски.
            PROMPT;
    }
}
