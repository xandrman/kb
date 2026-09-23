<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Processing status shown in the admin panel (FR-1).
 */
enum DocumentStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Duplicate = 'duplicate';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'В очереди',
            self::Duplicate => 'Дубликат',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Duplicate => 'warning',
        };
    }
}
