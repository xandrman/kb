<?php

namespace Tests\Unit;

use App\Services\ChunkLanguage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ChunkLanguageTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function russianOrEnglish(): array
    {
        return [
            'русский' => ['Холодильник предназначен для замораживания свежих продуктов, хранения замороженных продуктов и приготовления льда.'],
            'русская таблица' => ['Таблица 1 - Климатические классы / Умеренный расширенный, Символ = SN. Умеренный, Диапазон температуры = от +16 до +32'],
            'английский текст' => ['WARNING! Do not place hot food in the refrigerator. The temperature in the fridge compartment can be set from 2 to 8 degrees.'],
            'английская таблица' => ['Rated voltage, V = 220-240. Rated frequency, Hz = 50. Energy class = A+. Noise level, dB = 40'],
            'без языка' => ['4621159ND / 220 V / 50 Hz / A+'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function otherLanguages(): array
    {
        return [
            'украинский' => ['Холодильник відповідно до малюнку 1 призначений для заморожування свіжих харчових продуктів і зберігання.'],
            'казахский' => ['Тоңазытқышты пайдалану үшін қажет жалпы кеңістік миллиметрмен 2-суретте көрсетілген өлшемге сәйкес анықталады.'],
            'кыргызский' => ['Муздаткыч жаңы азык-түлүктү тоңдуруу, тоңдурулган азыктарды узак убакыт сактоо үчүн арналган.'],
            'таджикский' => ['Яхдон барои яхкунонии маҳсулоти хӯроквории тару тоза ва нигоҳдории дарозмуддати маҳсулот пешбинӣ шудааст.'],
            'азербайджанский' => ['Soyuducu şəkil 1 uyğun olaraq təzə ərzaqların dondurulması, dondurulmuş ərzaqların uzunmüddətli saxlanması üçün nəzərdə tutulub.'],
            'румынский' => ['Frigiderul este destinat congelării produselor alimentare proaspete și păstrării îndelungate a produselor congelate.'],
            'узбекский' => ["Sovutgichni elektr tarmog'iga ulang va displey yonishini kuting, keyin haroratni sozlang va eshikni yoping."],
            'узбекский со смешанными буквами' => ['Boshqaruv bloki 3 rasmga muvofiq displеy va boshqaruv tugmalariga (bundan kеyin - tugmalar) ega.'],
            'немецкий' => ['Schließen Sie das Netzkabel an die Steckdose an und warten Sie, bis das Display eingeschaltet ist.'],
        ];
    }

    public function test_function_words_are_not_counted_in_a_table(): void
    {
        $table = 'Battery Run Time, UPS = 6k. Backup Time (mins) = 60. Load = 1 kW. UPS = 10k. Backup Time (mins) = 240. Load = 2 kW. '
            .'UPS = 6k. Backup Time (mins) = 25. Load = 3 kW.';

        $this->assertTrue((new ChunkLanguage)->isRussianOrEnglish($table, hasTable: true));
        $this->assertFalse((new ChunkLanguage)->isRussianOrEnglish($table));
    }

    public function test_a_table_in_another_latin_language_is_still_dropped(): void
    {
        $this->assertFalse((new ChunkLanguage)->isRussianOrEnglish('Tensiune nominală, V = 220-240. Frecvență nominală, Hz = 50. Clasă energetică = A+. Nivel de zgomot, dB = 40', hasTable: true));
    }

    #[DataProvider('russianOrEnglish')]
    public function test_russian_english_and_language_neutral_chunks_are_kept(string $text): void
    {
        $this->assertTrue((new ChunkLanguage)->isRussianOrEnglish($text));
    }

    #[DataProvider('otherLanguages')]
    public function test_chunks_in_other_languages_are_dropped(string $text): void
    {
        $this->assertFalse((new ChunkLanguage)->isRussianOrEnglish($text));
    }
}
