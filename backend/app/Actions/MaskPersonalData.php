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
     * @return array{text: string, count: int} masked text and the number of distinct values replaced
     */
    public function handle(string $text): array
    {
        $fragments = [...$this->detectedByModel($text), ...$this->detectedByPattern($text)];

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

        return ['text' => $text, 'count' => count($masked)];
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
     * Numbers personal by their format alone: a SNILS and a card number passing the Luhn check are never an organization's.
     *
     * @return list<array{text: string, type: PersonalDataType}>
     */
    private function detectedByPattern(string $text): array
    {
        $found = [];

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
