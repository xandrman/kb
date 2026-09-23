<?php

namespace App\Actions;

use App\Enums\DoclingRoute;
use App\Models\Document;
use InvalidArgumentException;

class ChooseDoclingRoute
{
    /**
     * Pick the docling pipeline by file type; the PDF text-layer gate of ADR-0012 is not implemented yet.
     */
    public function handle(Document $document): DoclingRoute
    {
        return match ($document->mime_type) {
            // Встроенный OCR docling кириллицу не распознаёт: born-digital PDF берётся по текстовому слою без OCR
            'application/pdf' => DoclingRoute::Standard,
            'image/jpeg', 'image/png' => DoclingRoute::Vlm,
            default => throw new InvalidArgumentException("No docling route for {$document->mime_type}."),
        };
    }
}
