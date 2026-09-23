<?php

namespace App\Jobs;

use App\Actions\ExtractChunkGraph;
use App\Models\Document;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\StructuredOutput\Deserializer\DeserializerException;

/**
 * Graph stage of ingest for one chunk (FR-4); the chunks of a document run as one batch.
 */
class ExtractGraph implements ShouldQueue
{
    use Batchable, Queueable;

    /**
     * The number of times the job may be attempted.
     *
     * Попытки, а не retryUntil(): задачи корпуса ждут в очереди часами, и срок истёк бы до первого запуска.
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
     */
    public function __construct(
        public Document $document,
        public string $chunkId,
        public string $text,
    ) {
        $this->onQueue('graph');
    }

    /**
     * Execute the job.
     */
    public function handle(ExtractChunkGraph $extractGraph): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        try {
            $extractGraph->handle($this->text, $this->document->original_name);
        } catch (AgentException|DeserializerException $exception) {
            // Ответ не прошёл схему и после повтора внутри Neuron; при temperature 0 повтор задачи даст то же — чанк остаётся без графа
            Log::warning('Граф чанка не извлечён', [
                'document_id' => $this->document->id,
                'chunk_id' => $this->chunkId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
