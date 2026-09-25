<?php

namespace App\Filament\Resources\RestrictedChunkReads;

use App\Enums\AccessLevel;
use App\Filament\Resources\RestrictedChunkReads\Pages\ListRestrictedChunkReads;
use App\Models\RestrictedChunkRead;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Restricted document access log (SRS 6.4, 9.4): read only — an audit trail is not edited from the interface.
 */
class RestrictedChunkReadResource extends Resource
{
    protected static ?string $model = RestrictedChunkRead::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEye;

    protected static ?string $modelLabel = 'чтение';

    protected static ?string $pluralModelLabel = 'Журнал доступа';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Время')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('Пользователь')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('document.original_name')
                    ->label('Документ')
                    ->placeholder('удалён')
                    ->wrap()
                    ->searchable(),
                TextColumn::make('page_numbers')
                    ->label('Стр.')
                    ->formatStateUsing(fn (RestrictedChunkRead $record): string => implode(', ', $record->page_numbers ?? []))
                    ->placeholder('—'),
                TextColumn::make('access_level')
                    ->label('Гриф')
                    ->badge(),
                // Вопрос хранится уже маскированным: значений ПДн в журнале нет
                TextColumn::make('question')
                    ->label('Вопрос')
                    ->limit(120)
                    ->tooltip(fn (RestrictedChunkRead $record): string => $record->question)
                    ->wrap()
                    ->searchable(),
            ])
            ->filters([
                SelectFilter::make('access_level')
                    ->label('Гриф')
                    ->options([
                        AccessLevel::Internal->value => AccessLevel::Internal->getLabel(),
                        AccessLevel::Confidential->value => AccessLevel::Confidential->getLabel(),
                    ]),
                SelectFilter::make('user')
                    ->label('Пользователь')
                    ->relationship('user', 'name')
                    ->searchable(),
                SelectFilter::make('document')
                    ->label('Документ')
                    ->relationship('document', 'original_name')
                    ->searchable(),
                Filter::make('period')
                    ->schema([
                        DatePicker::make('from')->label('С'),
                        DatePicker::make('until')->label('По'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date))),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRestrictedChunkReads::route('/'),
        ];
    }
}
