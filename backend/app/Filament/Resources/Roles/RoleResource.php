<?php

namespace App\Filament\Resources\Roles;

use App\Enums\AccessLevel;
use App\Filament\Resources\Roles\Pages\ManageRoles;
use App\Models\Role;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'роль';

    protected static ?string $pluralModelLabel = 'Роли';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Имя — роль realm в Keycloak: при входе пользователю назначаются роли с совпадающим именем
                TextInput::make('name')
                    ->label('Роль Keycloak')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->disabledOn('edit'),
                Select::make('access_level')
                    ->label('Доступ до грифа')
                    ->options(AccessLevel::class)
                    ->placeholder('Только общедоступные')
                    ->helperText('Роль открывает документы с этим грифом и всеми менее строгими.'),
            ]);
    }

    // Удаления нет: роли приходят из Keycloak и создаются заново при следующем входе
    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Роль Keycloak')
                    ->searchable(),
                TextColumn::make('access_level')
                    ->label('Доступ до грифа')
                    ->badge()
                    ->placeholder('Только общедоступные'),
                TextColumn::make('users_count')
                    ->label('Пользователей')
                    ->counts('users'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageRoles::route('/'),
        ];
    }
}
