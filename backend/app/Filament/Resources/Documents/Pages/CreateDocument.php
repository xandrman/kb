<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Actions\StoreUploadedDocument;
use App\Enums\DocumentStatus;
use App\Filament\Resources\Documents\DocumentResource;
use App\Models\Document;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;

class CreateDocument extends CreateRecord
{
    protected static string $resource = DocumentResource::class;

    protected static bool $canCreateAnother = false;

    protected static ?string $title = 'Добавить документ';

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Document
    {
        return app(StoreUploadedDocument::class)->handle(
            $data['file'],
            Arr::except($data, 'file'),
            auth()->user(),
        );
    }

    protected function getCreatedNotification(): ?Notification
    {
        if ($this->record->status === DocumentStatus::Duplicate) {
            return Notification::make()
                ->warning()
                ->title('Такой файл уже загружен')
                ->body('Документ зарегистрирован, повторная обработка не запускалась.');
        }

        return Notification::make()
            ->success()
            ->title('Документ поставлен в очередь на обработку');
    }
}
