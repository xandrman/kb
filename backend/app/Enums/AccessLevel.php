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

    /**
     * Strictness order: a user cleared for a rank sees every lower rank (ADR-0007 filters paths by it).
     */
    public function rank(): int
    {
        return match ($this) {
            self::Public => 0,
            self::Internal => 1,
            self::Confidential => 2,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Public => 'Общедоступный',
            self::Internal => 'Служебный',
            self::Confidential => 'Конфиденциальный',
        };
    }
}
