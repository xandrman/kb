<?php

namespace App\Filament\Resources\Documents\Schemas;

use App\Enums\AccessLevel;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class DocumentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Файл не сохраняется Filament: его публикует StoreUploadedDocument по адресу sha256 (ADR-0018)
                FileUpload::make('file')
                    ->label('Файл')
                    ->required()
                    ->storeFiles(false)
                    ->acceptedFileTypes(['application/pdf', 'image/png', 'image/jpeg'])
                    ->maxSize(61440)
                    ->columnSpanFull(),
                // ТЗ 9.3: без SKU, типа, грифа и отдела-владельца RBAC и guardrails не работают
                TextInput::make('sku')
                    ->label('SKU')
                    ->required()
                    ->maxLength(255),
                // Отключённый тип не предлагается для новых загрузок, но остаётся у уже загруженных документов
                Select::make('document_type_id')
                    ->label('Тип документа')
                    ->relationship('documentType', 'name', fn (Builder $query) => $query->active())
                    ->required(),
                Select::make('access_level')
                    ->label('Гриф доступа')
                    ->options(AccessLevel::class)
                    ->required(),
                TextInput::make('owner_department')
                    ->label('Отдел-владелец')
                    ->required()
                    ->maxLength(255),
                DatePicker::make('document_date')
                    ->label('Дата документа'),
            ]);
    }
}
