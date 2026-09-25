<?php

namespace App\Actions;

use App\Enums\DocumentStatus;
use App\Models\Document;

class MarkDocumentFailed
{
    /**
     * Stop processing and show the reason in the admin panel (FR-1).
     */
    public function handle(Document $document, ?string $error): void
    {
        $document->update([
            'status' => DocumentStatus::Failed,
            'error' => $error,
        ]);
    }
}
