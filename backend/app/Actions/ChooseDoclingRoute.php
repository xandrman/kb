<?php

namespace App\Actions;

use App\Contracts\DocumentStorage;
use App\Enums\DoclingRoute;
use App\Models\Document;
use App\Services\PdfTextLayer;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class ChooseDoclingRoute
{
    /**
     * A page with fewer letters has no text layer: a scan or a blank page.
     */
    private const int MIN_PAGE_LETTERS = 20;

    /**
     * Unreadable characters above this share mean a garbage layer; born-digital PDFs of the pilot corpus stay under 0.5 %.
     */
    private const float MAX_UNREADABLE_SHARE = 0.01;

    /**
     * Pages without text above this share mean scans; in the pilot corpus scans have 91–100 %, born-digital PDFs at most 50 %
     * (a two-page manual with a blank back page).
     */
    private const float MAX_EMPTY_PAGE_SHARE = 0.5;

    /**
     * Characters a readable layer does not produce: U+FFFD, private-use glyphs, control codes, and the Latin-1 letters
     * Cyrillic turns into when a font without ToUnicode is read as a single-byte encoding.
     */
    private const string UNREADABLE = '/[\x{FFFD}\p{Co}\p{Cc}\x{00C0}-\x{00FF}]/u';

    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly PdfTextLayer $textLayer,
    ) {}

    /**
     * Pick the docling pipeline by file type and, for a PDF, by its text layer (ADR-0012).
     */
    public function handle(Document $document): DoclingRoute
    {
        return match ($document->mime_type) {
            'application/pdf' => $this->pdfRoute($document),
            'image/jpeg', 'image/png' => DoclingRoute::Vlm,
            default => throw new InvalidArgumentException("No docling route for {$document->mime_type}."),
        };
    }

    /**
     * Встроенный OCR docling кириллицу не распознаёт: born-digital PDF берётся по текстовому слою без OCR,
     * а PDF, чей слой не прошёл гейт, распознаёт VLM.
     */
    private function pdfRoute(Document $document): DoclingRoute
    {
        $file = $this->storage->get($document->digest);

        try {
            $pages = $this->textLayer->pages($file);
        } finally {
            is_resource($file) && fclose($file);
        }

        $layer = $pages === null ? null : $this->measure($pages);
        $failedCheck = $this->failedCheck($layer);
        $route = $failedCheck === null ? DoclingRoute::Standard : DoclingRoute::Vlm;

        // Доля PDF, ушедших на VLM, — предиктор нагрузки на GPU; по этим записям калибруются пороги гейта
        Log::info('Маршрут docling выбран по текстовому слою PDF', [
            'document_id' => $document->id,
            'route' => $route->value,
            'failed_check' => $failedCheck,
            ...$layer ?? [],
        ]);

        return $route;
    }

    /**
     * @param  list<string>  $pages
     * @return array{pages: int, letters: int, empty_pages: int, unreadable_share: float}
     */
    private function measure(array $pages): array
    {
        $letters = array_map(fn (string $page): int => (int) preg_match_all('/\p{L}/u', $page), $pages);
        $text = (string) preg_replace('/\s+/u', '', implode('', $pages));

        return [
            'pages' => count($pages),
            'letters' => array_sum($letters),
            'empty_pages' => count(array_filter($letters, fn (int $count): bool => $count < self::MIN_PAGE_LETTERS)),
            'unreadable_share' => $text === '' ? 0.0 : preg_match_all(self::UNREADABLE, $text) / mb_strlen($text),
        ];
    }

    /**
     * The three checks of ADR-0012 in turn: the layer exists, reads as text, covers the pages.
     *
     * @param  array{pages: int, letters: int, empty_pages: int, unreadable_share: float}|null  $layer
     */
    private function failedCheck(?array $layer): ?string
    {
        return match (true) {
            $layer === null => 'poppler не прочитал файл',
            $layer['letters'] < self::MIN_PAGE_LETTERS => 'нет текстового слоя',
            $layer['unreadable_share'] > self::MAX_UNREADABLE_SHARE => 'текстовый слой нечитаем',
            $layer['empty_pages'] / $layer['pages'] > self::MAX_EMPTY_PAGE_SHARE => 'текст меньше чем на половине страниц',
            default => null,
        };
    }
}
