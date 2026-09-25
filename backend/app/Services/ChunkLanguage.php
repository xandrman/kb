<?php

namespace App\Services;

/**
 * Tells whether a chunk is in Russian or English, the languages of the corpus (SRS 4.3).
 *
 * Эвристика по буквам, а не модель: русский отличается от других кириллических языков отсутствием их особых букв,
 * английский от других латинских — отсутствием диакритики и долей служебных слов. Откалибрована на многоязычном
 * руководстве (рус., укр., каз., азерб., рум., узб., тадж., кирг.).
 */
class ChunkLanguage
{
    /**
     * Fewer letters than this (numbers, codes, table cells) carry no language and are kept.
     */
    private const int MIN_LETTERS = 20;

    /**
     * Letters of Ukrainian, Belarusian, Kazakh, Kyrgyz and Tajik that Russian does not have.
     */
    private const string NON_RUSSIAN_CYRILLIC = 'іїєґўәғқңөұүһҳӣӯҷ';

    /**
     * Diacritics of Latin-script languages other than English (Azerbaijani, Romanian, Turkish, German, French…).
     */
    private const string NON_ENGLISH_LATIN = 'ăâîșțşţəğıöüäßéèêàçñáíóúìòùõãåøæœ';

    /**
     * @var list<string>
     */
    private const array ENGLISH_FUNCTION_WORDS = [
        'the', 'an', 'and', 'or', 'of', 'to', 'in', 'on', 'for', 'with', 'is', 'are', 'be', 'by', 'as', 'at',
        'it', 'this', 'that', 'from', 'not', 'do', 'if', 'you', 'your', 'can', 'should', 'must', 'will', 'when', 'which',
    ];

    /**
     * @param  bool  $hasTable  the chunk holds a docling table: its function words are not counted (ADR-0028)
     */
    public function isRussianOrEnglish(string $text, bool $hasTable = false): bool
    {
        $text = mb_strtolower($text);

        if (preg_match_all('/\p{L}/u', $text) < self::MIN_LETTERS) {
            return true;
        }

        $cyrillic = (int) preg_match_all('/\p{Cyrillic}/u', $text);
        $latin = (int) preg_match_all('/\p{Latin}/u', $text);

        return $cyrillic >= $latin
            ? $this->share($text, self::NON_RUSSIAN_CYRILLIC, $cyrillic) < 0.01
            : $this->isEnglish($text, $latin, $hasTable);
    }

    private function isEnglish(string $text, int $latin, bool $hasTable): bool
    {
        if ($this->share($text, self::NON_ENGLISH_LATIN, $latin) >= 0.01) {
            return false;
        }

        // Узбекская латиница: oʻ, gʻ через апостроф, и кириллическая «е» внутри латинских слов при наборе
        if (preg_match("/\\b\\p{Latin}*[og][ʻ'‘’]\\p{Latin}/u", $text) || preg_match('/\p{Latin}\p{Cyrillic}|\p{Cyrillic}\p{Latin}/u', $text)) {
            return false;
        }

        // Служебные слова решают только для связного текста: в таблице характеристик их нет и на английском.
        // Сериализованная таблица длиннее порога связного текста, поэтому она узнаётся по элементу docling, а не по числу слов
        if ($hasTable) {
            return true;
        }

        $words = array_filter(preg_split('/[^a-z]+/', $text, flags: PREG_SPLIT_NO_EMPTY) ?: [], fn (string $word): bool => strlen($word) > 1);

        if (count($words) < 15) {
            return true;
        }

        $functionWords = count(array_filter($words, fn (string $word): bool => in_array($word, self::ENGLISH_FUNCTION_WORDS, true)));

        return $functionWords / count($words) >= 0.08;
    }

    private function share(string $text, string $letters, int $total): float
    {
        return (int) preg_match_all('/['.$letters.']/u', $text) / max($total, 1);
    }
}
