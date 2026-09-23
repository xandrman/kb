<?php

namespace App\Actions;

use App\Enums\RelationType;
use App\Neuron\Nodes\GroundedContextNode;

class DetectContextLeak
{
    /**
     * A system prompt sentence shorter than this is too common to prove a leak.
     */
    private const int MIN_PROMPT_SENTENCE_WORDS = 5;

    /**
     * Named tags of the Qwen chat template (ADR-0009): tool calls and reasoning; special tokens <|…|> are matched as a class.
     *
     * @var list<string>
     */
    private const array TEMPLATE_TAGS = ['tool_call', 'tool_response', 'tools', 'function', 'parameter', 'think', 'thinking'];

    /**
     * Output guardrail (FR-8): what in the answer comes from the model's input, not from the documents — or null.
     *
     * Ищется только то, что наш код сам вставляет во вход модели и чего нет ни в документах, ни в обычной речи.
     * Название документа, SKU и страницы по отдельности — не утечка: документ пользователю доступен.
     *
     * @return string|null the rule that matched, for the guardrail log
     */
    public function handle(string $answer, string $systemPrompt): ?string
    {
        $rules = [
            'Разметка контекста' => '/'.preg_quote(GroundedContextNode::CONTEXT_OPEN, '/').'|'.preg_quote(GroundedContextNode::CONTEXT_CLOSE, '/').'/iu',
            'Заголовок фрагмента контекста' => '/'.GroundedContextNode::FRAGMENT_LABEL.'\s+\d+\s*[(:]|'.preg_quote(GroundedContextNode::ALSO_IN_LABEL, '/').'\s+«/u',
            'Связи графа знаний' => '/'.GroundedContextNode::FACT_LABEL.':|—\s*(?:'.implode('|', array_column(RelationType::cases(), 'value')).')\s*→/u',
            'Разметка шаблона чата модели' => '/<\|[a-z_]+\|>|<\/?(?:'.implode('|', self::TEMPLATE_TAGS).')[\s>=]/iu',
        ];

        foreach ($rules as $rule => $pattern) {
            if (preg_match($pattern, $answer)) {
                return $rule;
            }
        }

        return $this->quotesPrompt($answer, $systemPrompt) ? 'Фрагмент системного промпта' : null;
    }

    /**
     * Whether the answer repeats a sentence of the system prompt; the refusal the prompt prescribes does not count.
     */
    private function quotesPrompt(string $answer, string $systemPrompt): bool
    {
        $answer = self::normalize(str_replace(GroundedContextNode::REFUSAL, '', $answer));

        foreach (preg_split('/[.!?;\n]+/u', $systemPrompt) ?: [] as $sentence) {
            $sentence = self::normalize($sentence);

            if (count(explode(' ', $sentence)) >= self::MIN_PROMPT_SENTENCE_WORDS && str_contains($answer, $sentence)) {
                return true;
            }
        }

        return false;
    }

    private static function normalize(string $text): string
    {
        $text = str_replace(['«', '»', '"', '—', '–'], ['', '', '', '-', '-'], mb_strtolower($text));

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
