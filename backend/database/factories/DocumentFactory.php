<?php

namespace Database\Factories;

use App\Enums\AccessLevel;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'digest' => hash('sha256', fake()->uuid()),
            'mime_type' => 'application/pdf',
            'original_name' => fake()->slug().'.pdf',
            'size' => fake()->numberBetween(1024, 10 * 1024 * 1024),
            'sku' => fake()->bothify('SKU-#####'),
            'document_type_id' => DocumentType::factory(),
            'access_level' => fake()->randomElement(AccessLevel::cases()),
            'owner_department' => fake()->randomElement(['Сервис', 'Закупки', 'Юридический']),
            'document_date' => fake()->date(),
            'status' => DocumentStatus::Pending,
        ];
    }
}
