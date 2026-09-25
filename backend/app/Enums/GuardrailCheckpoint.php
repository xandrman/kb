<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Guardrail checkpoints of a question and its answer (FR-8): separate points before and after the RAG agent.
 */
enum GuardrailCheckpoint: string implements HasLabel
{
    case InputPersonalData = 'input_pii';
    case InputInjection = 'input_injection';
    case OutputPersonalData = 'output_pii';
    case OutputContext = 'output_context';

    public function getLabel(): string
    {
        return match ($this) {
            self::InputPersonalData => 'ПДн в вопросе',
            self::InputInjection => 'Prompt injection в вопросе',
            self::OutputPersonalData => 'ПДн в ответе',
            self::OutputContext => 'Утечка контекста в ответе',
        };
    }
}
