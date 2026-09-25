<?php

namespace App\Actions;

use App\Enums\InjectionCategory;
use App\Neuron\InjectionDetector;
use App\Neuron\Output\InjectionVerdict;
use NeuronAI\Chat\Messages\UserMessage;

class DetectPromptInjection
{
    /**
     * Obvious attacks recognised without the model, by kind.
     *
     * @var array<string, list<string>>
     */
    private const array PATTERNS = [
        'instruction_override' => [
            '/(игнорир\w*|забудь|забыть)\W+(?:\w+\W+){0,4}(инструкц|правил|указани)/u',
            '/\b(ignore|disregard|forget)\b\W+(?:\w+\W+){0,4}(instructions|rules|prompt)/u',
        ],
        'prompt_extraction' => [
            '/системн\w*\s+(промпт|инструкц|сообщени)/u',
            '/\bsystem\s*(prompt|message|instructions)\b/u',
            '/(покажи|выведи|повтори|раскрой|напиши)\W+(?:\w+\W+){0,3}(свои|твои|ваши)\W+(?:\w+\W+){0,2}(инструкц|промпт|правил)/u',
        ],
        'role_change' => [
            '/(ты\s+теперь|представь,?\s+что\s+ты|веди\s+себя\s+как|режим\s+разработчика)/u',
            '/\b(you\s+are\s+now|act\s+as|pretend\s+(to\s+be|you)|developer\s+mode)\b/u',
        ],
        'access_escalation' => [
            '/(обойди|обойти|игнорируй|без\s+учёта|без\s+учета|отключи)\W+(?:\w+\W+){0,3}(прав|доступ|гриф)/u',
            '/\b(bypass|ignore)\b\W+(?:\w+\W+){0,2}(access|permissions|clearance)/u',
        ],
    ];

    /**
     * The kind of prompt injection in the question, or null for an ordinary question (FR-8).
     *
     * Сначала шаблоны — очевидная атака не тратит вызов модели; затем классификатор на Qwen для перефразированных.
     * Ошибки модели не перехватываются: непроверенный вопрос дальше не идёт.
     */
    public function handle(string $question): ?InjectionCategory
    {
        $text = mb_strtolower($question);

        foreach (self::PATTERNS as $category => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $text)) {
                    return InjectionCategory::from($category);
                }
            }
        }

        // Агент копит историю диалога: на каждый вопрос — новый экземпляр
        /** @var InjectionVerdict $verdict */
        $verdict = app(InjectionDetector::class)->structured(new UserMessage($question), InjectionVerdict::class, maxRetries: 1);

        return $verdict->category === InjectionCategory::None ? null : $verdict->category;
    }
}
