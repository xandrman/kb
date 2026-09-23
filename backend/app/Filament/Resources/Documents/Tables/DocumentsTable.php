<?php

namespace App\Filament\Resources\Documents\Tables;

use App\Actions\ReprocessDocument;
use App\Models\Document;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
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
                self::durationColumn('docling_processing_time', 'Извлечение'),
                self::durationColumn('indexing_time', 'Индексация'),
                self::durationColumn('graph_time', 'Граф'),
                TextColumn::make('created_at')
                    ->label('Загружен')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('reprocess')
                    ->label('Обработать заново')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->requiresConfirmation()
                    ->modalDescription('Документ пройдёт обработку с начала: извлечение текста, чанкование, векторный индекс и граф.')
                    ->visible(fn (Document $record): bool => ReprocessDocument::isAllowed($record))
                    ->action(fn (Document $record, ReprocessDocument $reprocess) => $reprocess->handle($record)),
            ]);
    }

    /**
     * Duration of an ingest stage, seconds (SRS 6.2).
     */
    private static function durationColumn(string $name, string $label): TextColumn
    {
        return TextColumn::make($name)
            ->label($label)
            // В образе Alpine у ICU только данные en: русский разделитель задаётся явно
            ->numeric(decimalPlaces: 1, decimalSeparator: ',')
            ->suffix(' с')
            ->placeholder('—')
            ->sortable();
    }
}
