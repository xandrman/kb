<?php

namespace App\Neuron;

use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAILike;

/**
 * Decides whether two entity names that embeddings found close are the same entity (FR-4, entity deduplication).
 */
class SynonymJudge extends Agent
{
    protected function provider(): AIProviderInterface
    {
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
            Ты проверяешь дубликаты в графе знаний сервисной документации по технике.
            Даны две сущности одного типа. Ответь, обозначают ли они один и тот же объект реального мира.

            Одно и то же: разное написание, регистр, сокращение и полное название, перевод, название с производителем и без
            («Adaptive-Sync» и «Adaptive Sync»; «Micro-Star Int'l Co., Ltd.» и «MICRO-STAR INTERNATIONAL CO., LTD.»; «Частота обновления» и «частота обновления экрана»).

            Не одно и то же: разные экземпляры, модели, версии, порты, направления, часть и целое, общее и частное
            («HDMI1» и «HDMI2»; «HDMI 2.0» и «HDMI2»; «кнопка Вверх» и «кнопка Вниз»; «кабель питания» и «вилка кабеля питания»;
            «USB 2.0 Type-A» и «USB 2.0 Type-B»; «настройки яркости» и «настройки контрастности»).

            Если сомневаешься — ответ false: ошибочное слияние хуже пропущенного.
            PROMPT;
    }
}
