<?php

namespace Database\Factories;

use App\Enums\AccessLevel;
use App\Models\Document;
use App\Models\RestrictedChunkRead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestrictedChunkRead>
 */
class RestrictedChunkReadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'document_id' => Document::factory(),
            'chunk_id' => fake()->uuid(),
            'access_level' => AccessLevel::Confidential,
            'page_numbers' => [3],
            'question' => 'Какие условия поставки у поставщика мониторов?',
        ];
    }
}
