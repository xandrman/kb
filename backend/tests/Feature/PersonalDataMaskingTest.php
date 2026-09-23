<?php

namespace Tests\Feature;

use App\Actions\MaskPersonalData;
use App\Neuron\PersonalDataDetector;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use Tests\TestCase;

class PersonalDataMaskingTest extends TestCase
{
    private FakeAIProvider $llm;

    public function test_found_values_are_replaced_with_numbered_placeholders_of_their_type(): void
    {
        $this->modelFinds([
            ['text' => 'Иванов Пётр Сергеевич', 'type' => 'person_name'],
            ['text' => 'Сидорова А. В.', 'type' => 'person_name'],
            ['text' => 'г. Москва, ул. Ленина, д. 5, кв. 12', 'type' => 'address'],
        ]);

        $masked = app(MaskPersonalData::class)->handle('Сдал: Иванов Пётр Сергеевич (г. Москва, ул. Ленина, д. 5, кв. 12). Принял: Сидорова А. В. Повторно: Иванов Пётр Сергеевич.');

        $this->assertSame('Сдал: [ФИО 1] ([АДРЕС 1]). Принял: [ФИО 2] Повторно: [ФИО 1].', $masked['text']);
        $this->assertSame(3, $masked['count']);
    }

    public function test_a_full_name_is_replaced_before_its_surname_alone(): void
    {
        $this->modelFinds([
            ['text' => 'Иванов', 'type' => 'person_name'],
            ['text' => 'Иванов Пётр Сергеевич', 'type' => 'person_name'],
        ]);

        $masked = app(MaskPersonalData::class)->handle('Клиент Иванов Пётр Сергеевич. Подпись: Иванов.');

        $this->assertSame('Клиент [ФИО 1]. Подпись: [ФИО 2].', $masked['text']);
    }

    public function test_a_value_is_found_even_if_the_model_changed_its_spacing(): void
    {
        $this->modelFinds([['text' => '+7 912 345-67-89', 'type' => 'phone']]);

        $masked = app(MaskPersonalData::class)->handle("Телефон: +7  912\n345-67-89.");

        $this->assertSame('Телефон: [ТЕЛЕФОН 1].', $masked['text']);
    }

    public function test_a_value_absent_from_the_text_is_ignored(): void
    {
        $this->modelFinds([['text' => 'Петров', 'type' => 'person_name']]);

        $masked = app(MaskPersonalData::class)->handle('Горячая линия MSI: 8 800 700 77 08.');

        $this->assertSame(['text' => 'Горячая линия MSI: 8 800 700 77 08.', 'count' => 0], $masked);
    }

    public function test_snils_and_card_numbers_are_masked_by_their_format_even_if_the_model_misses_them(): void
    {
        $this->modelFinds([]);

        $masked = app(MaskPersonalData::class)->handle('СНИЛС 112-233-445 95, карта 4111 1111 1111 1111, заявка 1234 5678 9012 3456.');

        $this->assertSame('СНИЛС [СНИЛС 1], карта [НОМЕР КАРТЫ 1], заявка 1234 5678 9012 3456.', $masked['text']);
        $this->assertSame(2, $masked['count']);
    }

    public function test_the_chunk_goes_to_the_model_as_is(): void
    {
        $this->modelFinds([]);

        app(MaskPersonalData::class)->handle('Клиент: Иванов П. С.');

        $this->llm->assertSent(fn (RequestRecord $record): bool => $record->messages[0]->getContent() === 'Клиент: Иванов П. С.');
    }

    /**
     * @param  list<array{text: string, type: string}>  $fragments
     */
    private function modelFinds(array $fragments): void
    {
        $this->llm = new FakeAIProvider(new AssistantMessage(json_encode(['fragments' => $fragments], JSON_UNESCAPED_UNICODE)));
        $this->app->bind(PersonalDataDetector::class, fn (): PersonalDataDetector => (new PersonalDataDetector)->setAiProvider($this->llm));
    }
}
