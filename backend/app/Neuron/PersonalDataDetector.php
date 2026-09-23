<?php

namespace App\Neuron;

use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAILike;

/**
 * Finds personal data of individuals in a chunk before it is indexed (SRS 4.4, FR-8, ADR-0009).
 */
class PersonalDataDetector extends Agent
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
            Ты находишь персональные данные физических лиц во фрагменте документа, чтобы скрыть их до индексации.

            Ищи только данные конкретных людей: клиентов, сотрудников, подписантов, индивидуальных предпринимателей.
            - person_name — фамилия, имя, отчество или инициалы человека («Иванов П. С.», «Пётр Иванов»);
            - phone — телефон человека;
            - email — адрес электронной почты человека;
            - address — адрес проживания или регистрации человека, адрес доставки клиенту;
            - passport — паспортные данные одним фрагментом: серия и номер вместе со сведениями «выдан … (кем) … (когда)», если они есть;
            - snils — номер СНИЛС;
            - inn — ИНН физического лица или индивидуального предпринимателя;
            - birth_date — дата рождения;
            - bank_card — номер банковской карты.

            Не являются персональными данными и не выписываются:
            - названия, адреса, телефоны, сайты и e-mail организаций: производителей, сервисных центров, горячих линий, магазинов;
            - должности без имени, модели, артикулы и серийные номера оборудования, номера заявок, актов и договоров, даты документов.

            Выписывай каждый фрагмент ровно так, как он написан в тексте: те же буквы, регистр, пробелы и знаки.
            Если персональных данных нет, верни пустой список.
            PROMPT;
    }
}
