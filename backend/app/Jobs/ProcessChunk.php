<?php

namespace App\Jobs;

use App\Actions\IndexChunk;
use App\Models\Document;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One chunk through masking, vector index and graph (SRS 4.4, FR-3, FR-4); the chunks of a document run as one batch.
 */
class ProcessChunk implements ShouldQueue
{
    use Batchable, Queueable;

    /**
     * The number of times the job may be attempted.
     *
     * Попытки, а не retryUntil(): задачи корпуса ждут в очереди часами, и срок истёк бы до первого запуска.
     * Исчерпаны — пакет падает, и немаскированный текст так и не попадает в индекс (fail closed).
     */
    public int $tries = 5;

    /**
     * The number of seconds to wait before retrying after an exception.
     *
     * @var list<int>
     */
    public array $backoff = [30, 60, 120, 300];

    /**
     * Create a new job instance.
     *
     * @param  array{text: string, chunk_index: int, headings: list<string>|null, page_numbers: list<int>|null}  $chunk
     */
    public function __construct(
        public Document $document,
        public array $chunk,
    ) {
        $this->onQueue('graph');
    }

    /**
     * Execute the job.
     */
    public function handle(IndexChunk $indexChunk): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $masked = $indexChunk->handle($this->document, $this->chunk);

        // Последним шагом: при повторе задачи после сбоя значения не посчитаются дважды
        Document::whereKey($this->document->id)->increment('personal_data_count', $masked);
    }
}
