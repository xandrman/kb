<?php

namespace App\Actions;

use App\Contracts\DocumentStorage;
use App\Enums\DocumentStatus;
use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class StoreUploadedDocument
{
    public function __construct(private readonly DocumentStorage $storage) {}

    /**
     * Put the file into the blob store and register it; a file already stored is registered without reprocessing.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function handle(UploadedFile $file, array $metadata, User $uploader): Document
    {
        $digest = hash_file('sha256', $file->getRealPath());
        $isDuplicate = $this->storage->exists($digest);

        if (! $isDuplicate) {
            $digest = $this->storage->put($file->getRealPath());
        }

        // Blob уже опубликован: при сбое транзакции он остаётся сиротой до ручного sweep (ADR-0018)
        return DB::transaction(function () use ($file, $metadata, $uploader, $digest, $isDuplicate): Document {
            $document = Document::create([
                ...$metadata,
                'digest' => $digest,
                'mime_type' => $file->getMimeType(),
                'original_name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'status' => $isDuplicate ? DocumentStatus::Duplicate : DocumentStatus::Pending,
                'uploaded_by' => $uploader->getKey(),
            ]);

            // ADR-0018: дубликат не запускает конвейер повторно
            if (! $isDuplicate) {
                ProcessDocument::dispatch($document)->afterCommit();
            }

            return $document;
        });
    }
}
