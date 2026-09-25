<?php

namespace App\Actions;

use App\Enums\GuardrailAction;
use App\Enums\GuardrailCheckpoint;
use App\Models\GuardrailEvent;
use App\Models\User;

class RecordGuardrailEvent
{
    /**
     * Log a guardrail firing (FR-8, SRS 6.4); the question must already be masked.
     */
    public function handle(?User $user, GuardrailCheckpoint $checkpoint, GuardrailAction $action, string $reason, string $maskedQuestion): GuardrailEvent
    {
        return GuardrailEvent::create([
            'user_id' => $user?->id,
            'checkpoint' => $checkpoint,
            'action' => $action,
            'reason' => $reason,
            'question' => $maskedQuestion,
        ]);
    }
}
