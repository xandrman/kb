<?php

namespace App\Models;

use App\Enums\AccessLevel;
use Database\Factories\RestrictedChunkReadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One answer built on a restricted chunk (SRS 6.4): who got it, from which document, for which question.
 */
#[Fillable(['user_id', 'document_id', 'chunk_id', 'access_level', 'page_numbers', 'question'])]
class RestrictedChunkRead extends Model
{
    /** @use HasFactory<RestrictedChunkReadFactory> */
    use HasFactory;

    /**
     * Reads are only appended: there is no update time.
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
            'access_level' => AccessLevel::class,
            'page_numbers' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
