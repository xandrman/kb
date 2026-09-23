<?php

namespace App\Jobs;

use App\Actions\IndexDocumentChunks;
use App\Actions\MarkDocumentFailed;
use App\Models\Document;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * Last stage of ingest: chunks of the extracted document go to the vector index (FR-3).
 */
class IndexDocument implements ShouldQueue
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
        // kb-worker запущен с --tries=0: повторяем, пока docling, vLLM или Qdrant недоступны, но не бесконечно
        return now()->addMinutes(45);
    }

    /**
     * Execute the job.
     */
    public function handle(IndexDocumentChunks $indexChunks): void
    {
        try {
            $indexChunks->handle($this->document);
        } catch (RuntimeException $exception) {
            // Документ не разобран или пуст — повтор не поможет; сбои HTTP не RuntimeException и повторяются
            $this->fail($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(MarkDocumentFailed::class)->handle($this->document, $exception?->getMessage());
    }
}
