<?php

namespace App\Models;

use App\Enums\AccessLevel;
use App\Enums\DoclingRoute;
use App\Enums\DocumentStatus;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'digest', 'mime_type', 'original_name', 'size', 'sku', 'document_type_id',
    'access_level', 'owner_department', 'document_date', 'status', 'uploaded_by',
    'route', 'docling_task_id', 'docling_processing_time', 'indexing_time', 'graph_time', 'personal_data_count', 'error',
])]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'access_level' => AccessLevel::class,
            'document_date' => 'date',
            'status' => DocumentStatus::class,
            'route' => DoclingRoute::class,
            'docling_processing_time' => 'float',
            'indexing_time' => 'float',
            'graph_time' => 'float',
            'personal_data_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<DocumentType, $this>
     */
    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
