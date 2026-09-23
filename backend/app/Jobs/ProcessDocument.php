<?php

namespace App\Jobs;

use App\Actions\MarkDocumentFailed;
use App\Actions\SubmitDocumentExtraction;
use App\Actions\VerifyDocumentIntegrity;
use App\Models\Document;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * First stage of ingest: hand the original over to docling (ADR-0012).
 */
class ProcessDocument implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds to wait before retrying after an exception.
     */
    public int $backoff = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(public Document $document)
    {
        $this->onQueue('documents');
    }

    /**
     * Determine the time at which the job should time out.
     */
    public function retryUntil(): DateTimeInterface
    {
        // kb-worker запущен с --tries=0: предел задаёт срок, а не число попыток. ТЗ 6.2 — 30 минут на документ, плюс запас
        return now()->addMinutes(45);
    }

    /**
     * Execute the job.
     */
    public function handle(VerifyDocumentIntegrity $verifyIntegrity, SubmitDocumentExtraction $submitExtraction): void
    {
        if (! $verifyIntegrity->handle($this->document)) {
            // Повтор не поможет: файл на томе не совпадает с зарегистрированным
            $this->fail(new RuntimeException('Файл на томе не совпадает с дайджестом документа.'));

            return;
        }

        $submitExtraction->handle($this->document);
    }

    public function failed(?Throwable $exception): void
    {
        app(MarkDocumentFailed::class)->handle($this->document, $exception?->getMessage());
    }
}
