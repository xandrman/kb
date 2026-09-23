<?php

namespace App\Actions;

use App\Contracts\DocumentStorage;
use App\Enums\DocumentStatus;
use App\Models\Document;

class StoreDocumentExtraction
{
    public function __construct(private readonly DocumentStorage $storage) {}

    /**
     * Keep the DoclingDocument next to the original, so rechunking does not need another docling run (ADR-0018, ADR-0026).
     *
     * @param  array<string, mixed>  $doclingDocument
     */
    public function handle(Document $document, array $doclingDocument): void
    {
        $this->storage->putExtracted(
            $document->digest,
            json_encode($doclingDocument, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        $document->update(['status' => DocumentStatus::Extracted, 'error' => null]);
    }
}
