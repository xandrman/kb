<?php

namespace App\Filament\Resources\Documents\Tables;

use App\Models\Document;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // ADR-0021: статус обработки обновляется опросом не чаще раза в 5 с
            ->poll('5s')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('original_name')
                    ->label('Файл')
                    ->searchable(),
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable(),
                TextColumn::make('documentType.name')
                    ->label('Тип'),
                TextColumn::make('access_level')
                    ->label('Гриф')
                    ->badge(),
                TextColumn::make('owner_department')
                    ->label('Отдел'),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->tooltip(fn (Document $record): ?string => $record->error),
                TextColumn::make('created_at')
                    ->label('Загружен')
                    ->dateTime()
                    ->sortable(),
            ]);
    }
}
