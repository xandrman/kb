<?php

namespace Database\Factories;

use App\Enums\GuardrailAction;
use App\Enums\GuardrailCheckpoint;
use App\Models\GuardrailEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GuardrailEvent>
 */
class GuardrailEventFactory extends Factory
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
            'checkpoint' => GuardrailCheckpoint::InputPersonalData,
            'action' => GuardrailAction::Masked,
            'reason' => 'ФИО ×1',
            'question' => 'Клиент [ФИО 1] спрашивает про гарантию.',
        ];
    }
}
