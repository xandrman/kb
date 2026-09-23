<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Document access classification (SRS 4.2), owned by PostgreSQL (ADR-0014).
 */
enum AccessLevel: string implements HasLabel
{
    case Public = 'public';
    case Internal = 'internal';
    case Confidential = 'confidential';

    public function getLabel(): string
    {
        return match ($this) {
            self::Public => 'Общедоступный',
            self::Internal => 'Служебный',
            self::Confidential => 'Конфиденциальный',
        };
    }
}
