<?php

namespace App\Enums;

/**
 * Kinds of prompt injection in a question (FR-8); None — an ordinary question.
 */
enum InjectionCategory: string
{
    case None = 'none';
    case InstructionOverride = 'instruction_override';
    case PromptExtraction = 'prompt_extraction';
    case RoleChange = 'role_change';
    case AccessEscalation = 'access_escalation';

    /**
     * Reason in the guardrail log.
     */
    public function label(): string
    {
        return match ($this) {
            self::None => 'Обычный вопрос',
            self::InstructionOverride => 'Просьба игнорировать инструкции',
            self::PromptExtraction => 'Попытка получить системный промпт или служебный контекст',
            self::RoleChange => 'Попытка сменить роль ассистента',
            self::AccessEscalation => 'Попытка обойти права доступа',
        };
    }
}
