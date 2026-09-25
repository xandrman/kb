<?php

namespace App\Console\Commands;

use App\Enums\AccessLevel;
use App\Enums\PersonalDataType;
use App\Neuron\Output\ServiceActContent;
use App\Neuron\ServiceActWriter;
use Barryvdh\DomPDF\Facade\Pdf;
use DateTimeImmutable;
use Faker\Factory;
use Faker\Generator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use NeuronAI\Chat\Messages\UserMessage;
use RuntimeException;

#[Signature('testdata:service-acts
    {--count=50 : Сколько актов сгенерировать}
    {--seed=2026 : Зерно Faker: те же ПДн, модели и даты при повторном запуске}
    {--template= : HTML-шаблон акта; по умолчанию docs/templates/akt-servisnogo-obsluzhivaniya.html}
    {--registry= : Реестр публичного корпуса; по умолчанию testdata/registry.json}
    {--out= : Каталог для PDF и manifest.json; по умолчанию testdata/synthetic}')]
#[Description('Сгенерировать синтетические акты сервисного обслуживания с ПДн для пилотного корпуса (ТЗ 4.5, компонент 1)')]
class GenerateServiceActs extends Command
{
    /**
     * Corpus page limit (SRS 4.3) with the accepted 1–2 page overrun; longer registry files stay out of the corpus.
     */
    private const int CORPUS_MAX_PAGES = 52;

    private const string DOCUMENT_TYPE = 'Сервисный акт';

    private const string OWNER_DEPARTMENT = 'Сервисный центр';

    /**
     * Weighted service types: warranty repairs dominate a service centre's flow.
     */
    private const array SERVICE_TYPES = [
        'ГАРАНТИЙНЫЙ РЕМОНТ', 'ГАРАНТИЙНЫЙ РЕМОНТ', 'ГАРАНТИЙНЫЙ РЕМОНТ', 'ГАРАНТИЙНЫЙ РЕМОНТ', 'ГАРАНТИЙНЫЙ РЕМОНТ', 'ГАРАНТИЙНЫЙ РЕМОНТ',
        'ПЛАТНЫЙ РЕМОНТ', 'ПЛАТНЫЙ РЕМОНТ', 'ПЛАТНЫЙ РЕМОНТ',
        'ДИАГНОСТИКА',
    ];

    public function handle(): int
    {
        $template = File::get($this->option('template') ?: base_path('../docs/templates/akt-servisnogo-obsluzhivaniya.html'));
        $registry = json_decode(File::get($this->option('registry') ?: base_path('../testdata/registry.json')), true, flags: JSON_THROW_ON_ERROR);
        $out = $this->option('out') ?: base_path('../testdata/synthetic');
        File::ensureDirectoryExists($out);

        $faker = Factory::create('ru_RU');
        $faker->seed((int) $this->option('seed'));

        $related = $this->modelsFrom($registry);
        if ($related === []) {
            throw new RuntimeException('В реестре нет моделей SNR: не к чему привязать акты.');
        }
        $models = $faker->shuffle(array_keys($related));

        $organization = $this->organization($faker);
        $representatives = array_map(fn (): string => $faker->name(), range(1, 4));

        $manifest = [];
        for ($number = 1; $number <= (int) $this->option('count'); $number++) {
            $model = $models[($number - 1) % count($models)];
            $serviceType = $faker->randomElement(self::SERVICE_TYPES);
            $content = $this->write($model, $serviceType);

            $actDate = DateTimeImmutable::createFromMutable($faker->dateTimeBetween('2025-01-01', '2026-08-31'));
            $serviceDays = $faker->numberBetween(1, 20);
            $customer = [
                PersonalDataType::PersonName->value => $faker->name(),
                PersonalDataType::Address->value => $faker->address().', кв. '.$faker->numberBetween(1, 300),
                PersonalDataType::Phone->value => $faker->numerify('+7(9##)###-##-##'),
                PersonalDataType::Email->value => $faker->safeEmail(),
            ];
            $representative = $faker->randomElement($representatives);

            $fields = $organization + [
                'act_number' => sprintf('СЦ-%06d', $faker->unique()->numberBetween(1, 999999)),
                'act_date' => $actDate->format('d.m.Y'),
                'service_type' => $serviceType,
                'customer_name' => $customer[PersonalDataType::PersonName->value],
                'customer_address' => $customer[PersonalDataType::Address->value],
                'customer_phone' => $customer[PersonalDataType::Phone->value],
                'customer_email' => $customer[PersonalDataType::Email->value],
                'product_name' => $content->productName,
                'serial_number' => strtoupper($faker->bothify('???-##########')),
                'complaint' => $content->complaint,
                'received_date' => $actDate->modify("-{$serviceDays} days")->format('d.m.Y'),
                'completed_date' => $actDate->format('d.m.Y'),
                'result' => $content->result,
                'work_description' => $content->workDescription,
                'service_days' => (string) $serviceDays,
                'rep_name' => $representative,
            ];

            $filename = sprintf('service-act-%03d.pdf', $number);
            Pdf::loadHTML($this->fill($template, $fields))->setPaper('a4')->save("{$out}/{$filename}");

            $accessLevel = $faker->boolean(20) ? AccessLevel::Confidential : AccessLevel::Internal;
            $manifest[] = [
                'filename' => $filename,
                'sku' => $model,
                'document_type' => self::DOCUMENT_TYPE,
                'access_level' => $accessLevel->value,
                'owner_department' => self::OWNER_DEPARTMENT,
                'document_date' => $actDate->format('Y-m-d'),
                'personal_data' => [
                    ...array_map(
                        fn (string $type, string $value): array => ['type' => $type, 'value' => $value],
                        array_keys($customer),
                        $customer,
                    ),
                    ['type' => PersonalDataType::PersonName->value, 'value' => $representative],
                ],
                'related' => $related[$model],
                'fields' => $fields,
            ];

            $this->line("{$filename}: {$model}, {$serviceType}, {$accessLevel->getLabel()}");
        }

        File::put("{$out}/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->info("Сгенерировано актов: {$this->option('count')} → {$out}");

        return self::SUCCESS;
    }

    /**
     * SNR models named in corpus file names, each with the corpus files that mention it: the multi-hop links of an act.
     *
     * @param  list<array{filename: string, pages: int}>  $registry
     * @return array<string, list<string>>
     */
    private function modelsFrom(array $registry): array
    {
        $models = [];
        foreach ($registry as $entry) {
            if ($entry['pages'] > self::CORPUS_MAX_PAGES) {
                continue;
            }
            preg_match_all('/SNR-[A-Z0-9][A-Z0-9().-]*[A-Z0-9)]/i', pathinfo($entry['filename'], PATHINFO_FILENAME), $matches);
            foreach ($matches[0] as $model) {
                // «SNR-UPS-ONRT-XXXX-LiYY» names a whole series, not a model a customer brings in.
                if (! str_contains(strtoupper($model), 'XX')) {
                    $models[strtoupper($model)][] = $entry['filename'];
                }
            }
        }

        return $models;
    }

    private function write(string $model, string $serviceType): ServiceActContent
    {
        return app(ServiceActWriter::class)->structured(
            new UserMessage("Модель: {$model}\nТип обслуживания: {$serviceType}"),
            ServiceActContent::class,
            maxRetries: 2,
        );
    }

    /**
     * The service centre issuing every act: a fictional TechnoMart branch with random, non-registered requisites.
     *
     * @return array<string, string>
     */
    private function organization(Generator $faker): array
    {
        return [
            'org_name' => 'Общество с ограниченной ответственностью "ТехноМарт"',
            'org_short_name' => 'ООО "ТехноМарт"',
            'org_inn' => $faker->numerify('##########'),
            'org_kpp' => $faker->numerify('#########'),
            'org_legal_address' => $faker->address().', оф. '.$faker->numberBetween(1, 50),
            'org_branch_address' => $faker->address(),
        ];
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function fill(string $template, array $fields): string
    {
        $html = strtr($template, array_combine(
            array_map(fn (string $name): string => "{{{$name}}}", array_keys($fields)),
            array_map(fn (string $value): string => e($value), $fields),
        ));

        if (preg_match('/\{\{(\w+)\}\}/', $html, $unfilled)) {
            throw new RuntimeException("В шаблоне остался незаполненный плейсхолдер {$unfilled[1]}.");
        }

        return $html;
    }
}
