<?php

namespace App\Actions;

use App\Enums\PersonalDataType;
use App\Neuron\Output\DetectedPersonalData;
use App\Neuron\Output\PersonalDataFragment;
use App\Neuron\PersonalDataDetector;
use NeuronAI\Chat\Messages\UserMessage;

class MaskPersonalData
{
    /**
     * Replace personal data of individuals with typed placeholders before the text reaches any index (SRS 4.4, FR-8).
     *
     * Ошибки не перехватываются: если модель недоступна или ответ не прошёл схему, текст не должен уйти в индекс немаскированным.
     *
     * @return array{text: string, count: int, types: array<string, int>} masked text, distinct values replaced, their number per type
     */
    public function handle(string $text): array
    {
        return $this->replace($text, [...$this->detectedByModel($text), ...$this->detectedByPattern($text, withContacts: false)]);
    }

    /**
     * Output guardrail (FR-8): mask by format alone — phones, e-mails, SNILS, card numbers — leaving values found in $allowed.
     *
     * Без модели: ответ проверяется быстро. Контакты организаций законно цитируются из фрагментов, которые уже прошли
     * маскирование при загрузке; значение, которого в них нет, модель взяла не из документов — оно и скрывается.
     *
     * @return array{text: string, count: int, types: array<string, int>} masked text, distinct values replaced, their number per type
     */
    public function handleByPatterns(string $text, string $allowed = ''): array
    {
        return $this->replace($text, array_values(array_filter(
            $this->detectedByPattern($text, withContacts: true),
            fn (array $fragment): bool => ! $this->occursIn($fragment, $allowed),
        )));
    }

    /**
     * @param  list<array{text: string, type: PersonalDataType}>  $fragments
     * @return array{text: string, count: int, types: array<string, int>}
     */
    private function replace(string $text, array $fragments): array
    {
        // Длинные фрагменты — первыми: «Иванов Пётр Сергеевич» не должен распасться на уже заменённое «Иванов»
        usort($fragments, fn (array $left, array $right): int => mb_strlen($right['text']) <=> mb_strlen($left['text']));

        $numbers = [];
        $masked = [];

        foreach ($fragments as $fragment) {
            $key = $fragment['type']->value.':'.$fragment['text'];

            if (isset($masked[$key])) {
                continue;
            }

            // Пробелы и переносы модель может передать иначе, чем в тексте: сравниваем фрагмент с любыми пробельными символами
            $pattern = '/'.implode('\s+', array_map(fn (string $word): string => preg_quote($word, '/'), preg_split('/\s+/u', trim($fragment['text'])) ?: [])).'/u';

            if (! preg_match($pattern, $text)) {
                continue;
            }

            $numbers[$fragment['type']->value] = ($numbers[$fragment['type']->value] ?? 0) + 1;
            $text = (string) preg_replace($pattern, '['.$fragment['type']->placeholder().' '.$numbers[$fragment['type']->value].']', $text);
            $masked[$key] = true;
        }

        return ['text' => $text, 'count' => count($masked), 'types' => $numbers];
    }

    /**
     * Whether the value is in the allowed text: numbers are compared by digits, phones without the country code, e-mails case-insensitively.
     *
     * @param  array{text: string, type: PersonalDataType}  $fragment
     */
    private function occursIn(array $fragment, string $allowed): bool
    {
        if ($fragment['type'] === PersonalDataType::Email) {
            return str_contains(mb_strtolower($allowed), mb_strtolower($fragment['text']));
        }

        $digits = (string) preg_replace('/\D/', '', $fragment['text']);

        // «8 800…» и «+7 800…» — один номер: телефон сравнивается без кода страны
        if ($fragment['type'] === PersonalDataType::Phone) {
            $digits = substr($digits, -10);
        }

        return str_contains((string) preg_replace('/\D/', '', $allowed), $digits);
    }

    /**
     * Human-readable summary of what was masked, without the values: «ФИО ×1, телефон ×1».
     *
     * @param  array<string, int>  $types
     */
    public static function summary(array $types): string
    {
        // В порядке объявления видов, а не находки: одна и та же причина пишется одинаково
        $present = array_filter(PersonalDataType::cases(), fn (PersonalDataType $type): bool => isset($types[$type->value]));

        return implode(', ', array_map(fn (PersonalDataType $type): string => $type->label()." ×{$types[$type->value]}", $present));
    }

    /**
     * @return list<array{text: string, type: PersonalDataType}>
     */
    private function detectedByModel(string $text): array
    {
        // Агент копит историю диалога: на каждый чанк — новый экземпляр
        /** @var DetectedPersonalData $detected */
        $detected = app(PersonalDataDetector::class)->structured(new UserMessage($text), DetectedPersonalData::class, maxRetries: 1);

        return array_map(
            fn (PersonalDataFragment $fragment): array => ['text' => $fragment->text, 'type' => $fragment->type],
            $detected->fragments,
        );
    }

    /**
     * Values recognised by format. A SNILS and a card number passing the Luhn check are never an organization's;
     * phones and e-mails may be, so they are looked for only when the caller filters out the allowed ones.
     *
     * @return list<array{text: string, type: PersonalDataType}>
     */
    private function detectedByPattern(string $text, bool $withContacts): array
    {
        $found = [];

        if ($withContacts) {
            preg_match_all('/(?<![\w@.])(?:\+7|8)[\s\-(]*\d{3}[\s\-)]*\d{3}[\s\-]*\d{2}[\s\-]*\d{2}(?!\d)/u', $text, $phones);
            foreach ($phones[0] as $phone) {
                $found[] = ['text' => $phone, 'type' => PersonalDataType::Phone];
            }

            preg_match_all('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/u', $text, $emails);
            foreach ($emails[0] as $email) {
                $found[] = ['text' => $email, 'type' => PersonalDataType::Email];
            }
        }

        preg_match_all('/\b\d{3}-\d{3}-\d{3}[ -]\d{2}\b/u', $text, $snils);
        foreach ($snils[0] as $number) {
            $found[] = ['text' => $number, 'type' => PersonalDataType::Snils];
        }

        preg_match_all('/\b(?:\d{4}[ -]?){3}\d{4}\b/u', $text, $cards);
        foreach ($cards[0] as $number) {
            if ($this->passesLuhn((string) preg_replace('/\D/', '', $number))) {
                $found[] = ['text' => $number, 'type' => PersonalDataType::BankCard];
            }
        }

        return $found;
    }

    private function passesLuhn(string $digits): bool
    {
        $sum = 0;

        foreach (array_reverse(str_split($digits)) as $position => $digit) {
            $value = (int) $digit * ($position % 2 === 1 ? 2 : 1);
            $sum += $value > 9 ? $value - 9 : $value;
        }

        return $sum % 10 === 0;
    }
}
