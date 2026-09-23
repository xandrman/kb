<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Document access classification (SRS 4.2), owned by PostgreSQL (ADR-0014).
 */
enum AccessLevel: string implements HasColor, HasLabel
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

    /**
     * What a role granted this level opens: the level itself and every less strict one.
     */
    public function clearanceLabel(): string
    {
        return match ($this) {
            self::Public => 'Общедоступные',
            self::Internal => 'Общедоступные и служебные',
            self::Confidential => 'Общедоступные, служебные и конфиденциальные',
        };
    }

    /**
     * Every level a role granted this one opens, from the least strict.
     *
     * @return list<self>
     */
    public function opens(): array
    {
        return array_values(array_filter(self::cases(), fn (self $level): bool => $level->rank() <= $this->rank()));
    }

    /**
     * @return array<string, string>
     */
    public static function clearanceOptions(): array
    {
        return array_combine(
            array_map(fn (self $level): string => $level->value, self::cases()),
            array_map(fn (self $level): string => $level->clearanceLabel(), self::cases()),
        );
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Public => 'Общедоступный',
            self::Internal => 'Служебный',
            self::Confidential => 'Конфиденциальный',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Public => 'success',
            self::Internal => 'warning',
            self::Confidential => 'danger',
        };
    }
}
