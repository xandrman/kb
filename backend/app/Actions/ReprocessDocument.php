<?php

namespace App\Actions;

use App\Enums\DocumentStatus;
use App\Jobs\ProcessDocument;
use App\Models\Document;

class ReprocessDocument
{
    /**
     * Run the whole ingest again from the original: extraction, chunks, vectors and graph (FR-1).
     */
    public function handle(Document $document): void
    {
        $document->update([
            'status' => DocumentStatus::Pending,
            'docling_task_id' => null,
            'docling_processing_time' => null,
            'indexing_time' => null,
            'graph_time' => null,
            'personal_data_count' => 0,
            'error' => null,
        ]);

        ProcessDocument::dispatch($document);
    }

    /**
     * Pending and in-flight documents are skipped: a second run would race the first one.
     */
    public static function isAllowed(Document $document): bool
    {
        /** @var DocumentStatus $status */
        $status = $document->status;

        return in_array($status, [DocumentStatus::Extracted, DocumentStatus::Processed, DocumentStatus::Failed], true);
    }
}
