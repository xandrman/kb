<?php

namespace App\Models;

use App\Enums\GuardrailAction;
use App\Enums\GuardrailCheckpoint;
use Database\Factories\GuardrailEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One guardrail firing (FR-8): which checkpoint, what it did and why, for which question.
 */
#[Fillable(['user_id', 'checkpoint', 'action', 'reason', 'question'])]
class GuardrailEvent extends Model
{
    /** @use HasFactory<GuardrailEventFactory> */
    use HasFactory;

    /**
     * Events are only appended: there is no update time.
     */
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'checkpoint' => GuardrailCheckpoint::class,
            'action' => GuardrailAction::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
