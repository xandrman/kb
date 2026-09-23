<?php

namespace App\Jobs;

use App\Models\Document;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessDocument implements ShouldQueue
{
    use Queueable;

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
    public function handle(): void
    {
        // Заглушка: конвейер извлечения и индексации (ADR-0012) ещё не реализован
    }
}
