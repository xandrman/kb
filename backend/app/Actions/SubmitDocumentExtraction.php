<?php

namespace App\Actions;

use App\Contracts\DocumentStorage;
use App\Enums\DoclingRoute;
use App\Enums\DocumentStatus;
use App\Jobs\PollExtraction;
use App\Models\Document;
use App\Services\DoclingClient;

class SubmitDocumentExtraction
{
    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly DoclingClient $docling,
        private readonly ChooseDoclingRoute $chooseRoute,
    ) {}

    /**
     * Hand the original over to docling and start waiting for the result (ADR-0012).
     *
     * @param  DoclingRoute|null  $route  the pipeline to use instead of the one chosen by file type and text layer
     */
    public function handle(Document $document, ?DoclingRoute $route = null): void
    {
        $route ??= $this->chooseRoute->handle($document);
        $file = $this->storage->get($document->digest);

        try {
            $taskId = $this->docling->submit($file, $document->original_name, $route);
        } finally {
            is_resource($file) && fclose($file);
        }

        $document->update([
            'status' => DocumentStatus::Extracting,
            'route' => $route,
            'docling_task_id' => $taskId,
            'error' => null,
        ]);

        PollExtraction::dispatch($document);
    }
}
