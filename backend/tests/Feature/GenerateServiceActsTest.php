<?php

namespace Tests\Feature;

use App\Neuron\ServiceActWriter;
use Illuminate\Support\Facades\File;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use Tests\TestCase;

class GenerateServiceActsTest extends TestCase
{
    private string $directory;

    private FakeAIProvider $llm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/service-acts-'.uniqid();
        File::ensureDirectoryExists($this->directory);
        File::put("{$this->directory}/template.html", '<p>{{act_number}} {{service_type}} {{customer_name}} {{product_name}} {{complaint}} {{org_short_name}}</p>');
        File::put("{$this->directory}/registry.json", json_encode([
            ['url' => 'https://example.org/1.pdf', 'filename' => 'Паспорт устройства SNR-UPS-LID-1500.pdf', 'pages' => 12],
            ['url' => 'https://example.org/2.pdf', 'filename' => 'Паспорт_SNR-UPS-ONRT-XXXX-LiYY.pdf', 'pages' => 8],
            ['url' => 'https://example.org/3.pdf', 'filename' => 'NetAgent_9_User_Manual_SNR-UPS-SNMP-9.pdf', 'pages' => 115],
        ]));

        $this->llm = new FakeAIProvider(...array_fill(0, 2, new AssistantMessage(json_encode([
            'productName' => 'Источник бесперебойного питания SNR-UPS-LID-1500',
            'complaint' => 'Пищит и не держит нагрузку',
            'workDescription' => 'Проведена диагностика, заменена АКБ.',
            'result' => 'Отремонтирован',
        ]))));
        $this->app->bind(ServiceActWriter::class, fn (): ServiceActWriter => (new ServiceActWriter)->setAiProvider($this->llm));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_acts_are_rendered_to_pdf_with_a_manifest_of_metadata_personal_data_and_corpus_links(): void
    {
        $this->artisan('testdata:service-acts', [
            '--count' => 2,
            '--template' => "{$this->directory}/template.html",
            '--registry' => "{$this->directory}/registry.json",
            '--out' => "{$this->directory}/out",
        ])->assertSuccessful();

        $this->assertStringStartsWith('%PDF', File::get("{$this->directory}/out/service-act-001.pdf"));
        $this->assertFileExists("{$this->directory}/out/service-act-002.pdf");

        $manifest = json_decode(File::get("{$this->directory}/out/manifest.json"), true);
        $this->assertCount(2, $manifest);
        $act = $manifest[0];
        $this->assertSame('SNR-UPS-LID-1500', $act['sku'], 'Series masks and files over the corpus page limit give no models.');
        $this->assertSame(['Паспорт устройства SNR-UPS-LID-1500.pdf'], $act['related']);
        $this->assertSame('Сервисный акт', $act['document_type']);
        $this->assertContains($act['access_level'], ['internal', 'confidential']);
        $this->assertSame(
            ['person_name', 'address', 'phone', 'email', 'person_name'],
            array_column($act['personal_data'], 'type'),
        );
        $this->assertSame($act['fields']['customer_name'], $act['personal_data'][0]['value']);
        $this->assertSame('Пищит и не держит нагрузку', $act['fields']['complaint']);

        $this->llm->assertSent(fn (RequestRecord $record): bool => str_starts_with($record->messages[0]->getContent(), "Модель: SNR-UPS-LID-1500\nТип обслуживания: "));
    }

    public function test_a_template_placeholder_the_act_does_not_fill_fails_the_run(): void
    {
        File::put("{$this->directory}/template.html", '<p>{{act_number}} {{warranty_months}}</p>');

        $this->expectExceptionMessage('warranty_months');

        $this->artisan('testdata:service-acts', [
            '--count' => 1,
            '--template' => "{$this->directory}/template.html",
            '--registry' => "{$this->directory}/registry.json",
            '--out' => "{$this->directory}/out",
        ]);
    }
}
