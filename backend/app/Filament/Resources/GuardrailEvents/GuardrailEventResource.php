<?php

namespace App\Filament\Resources\GuardrailEvents;

use App\Enums\GuardrailAction;
use App\Enums\GuardrailCheckpoint;
use App\Filament\Resources\GuardrailEvents\Pages\ListGuardrailEvents;
use App\Models\GuardrailEvent;
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
 * Guardrail log (FR-8, SRS 6.4, 9.4): read only — an audit trail is not edited from the interface.
 */
class GuardrailEventResource extends Resource
{
    protected static ?string $model = GuardrailEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static ?string $modelLabel = 'срабатывание';

    protected static ?string $pluralModelLabel = 'Журнал guardrails';

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
                TextColumn::make('checkpoint')
                    ->label('Точка')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('action')
                    ->label('Действие')
                    ->badge(),
                TextColumn::make('reason')
                    ->label('Причина')
                    ->wrap(),
                // Вопрос хранится уже маскированным: значений ПДн в журнале нет
                TextColumn::make('question')
                    ->label('Вопрос')
                    ->limit(120)
                    ->tooltip(fn (GuardrailEvent $record): string => $record->question)
                    ->wrap()
                    ->searchable(),
            ])
            ->filters([
                SelectFilter::make('checkpoint')
                    ->label('Точка')
                    ->options(GuardrailCheckpoint::class),
                SelectFilter::make('action')
                    ->label('Действие')
                    ->options(GuardrailAction::class),
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
            'index' => ListGuardrailEvents::route('/'),
        ];
    }
}
