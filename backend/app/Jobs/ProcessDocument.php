<?php

namespace App\Jobs;

use App\Models\Document;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessDocument implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public Document $document) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Заглушка: конвейер извлечения и индексации (ADR-0012) ещё не реализован
    }
}
