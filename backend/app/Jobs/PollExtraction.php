<?php

namespace App\Jobs;

use App\Actions\MarkDocumentFailed;
use App\Actions\StoreDocumentExtraction;
use App\Models\Document;
use App\Services\DoclingClient;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * Wait for docling to finish the extraction started by ProcessDocument.
 */
class PollExtraction implements ShouldQueue
{
    use Queueable;

    private const int POLL_INTERVAL = 15;

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
        // Ожидание — повторная постановка задачи: воркер не блокируется на время обработки в docling (ТЗ 6.2 — до 30 минут)
        return now()->addMinutes(45);
    }

    /**
     * Execute the job.
     */
    public function handle(DoclingClient $docling, StoreDocumentExtraction $storeExtraction): void
    {
        $taskId = $this->document->docling_task_id;

        match ($docling->status($taskId)) {
            'success' => $this->complete($docling->result($taskId), $storeExtraction),
            'failure' => $this->fail(new RuntimeException("docling не обработал документ (задача {$taskId}).")),
            default => $this->release(self::POLL_INTERVAL),
        };
    }

    public function failed(?Throwable $exception): void
    {
        app(MarkDocumentFailed::class)->handle($this->document, $exception?->getMessage());
    }

    /**
     * @param  array{status: string, errors: array<int, mixed>, document: array<string, mixed>}  $result
     */
    private function complete(array $result, StoreDocumentExtraction $storeExtraction): void
    {
        if ($result['status'] !== 'success') {
            $this->fail(new RuntimeException('docling вернул статус '.$result['status'].': '.json_encode($result['errors'], JSON_UNESCAPED_UNICODE)));

            return;
        }

        $storeExtraction->handle($this->document, $result['document']['json_content']);
    }
}
