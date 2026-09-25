<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

/**
 * Reads the text layer of a PDF page by page with pdftotext (poppler-utils), without OCR.
 */
class PdfTextLayer
{
    /**
     * @param  resource  $file
     * @return list<string>|null the text of every page; null when poppler cannot read the file
     */
    public function pages($file): ?array
    {
        $result = Process::input($file)->timeout(120)->run(['pdftotext', '-enc', 'UTF-8', '-', '-']);

        if ($result->failed()) {
            return null;
        }

        // pdftotext завершает каждую страницу, в том числе последнюю, символом перевода формата
        $pages = explode("\f", $result->output());
        array_pop($pages);

        return $pages;
    }
}
