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
    case Extracting = 'extracting';
    case Extracted = 'extracted';
    case Indexed = 'indexed';
    case Failed = 'failed';
    case Duplicate = 'duplicate';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'В очереди',
            self::Extracting => 'Извлечение',
            self::Extracted => 'Извлечён',
            self::Indexed => 'Проиндексирован',
            self::Failed => 'Ошибка',
            self::Duplicate => 'Дубликат',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Extracting => 'info',
            self::Extracted => 'info',
            self::Indexed => 'success',
            self::Failed => 'danger',
            self::Duplicate => 'warning',
        };
    }
}
