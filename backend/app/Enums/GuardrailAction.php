<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * What a guardrail did: hid values and let the request through, or stopped it.
 */
enum GuardrailAction: string implements HasColor, HasLabel
{
    case Masked = 'masked';
    case Blocked = 'blocked';

    public function getLabel(): string
    {
        return match ($this) {
            self::Masked => 'Скрыто',
            self::Blocked => 'Заблокировано',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Masked => 'warning',
            self::Blocked => 'danger',
        };
    }
}
