<?php

namespace App\Services;

use App\Enums\DoclingRoute;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Asynchronous conversion API of docling-serve (ADR-0012).
 */
class DoclingClient
{
    /**
     * Submit a file for conversion into a DoclingDocument and return the docling task id.
     *
     * @param  resource  $file
     */
    public function submit($file, string $fileName, DoclingRoute $route): string
    {
        return $this->request()
            ->attach('files', $file, $fileName)
            ->post('/v1/convert/file/async', [
                'to_formats' => 'json',
                ...$this->routeOptions($route),
            ])
            ->throw()
            ->json('task_id');
    }

    /**
     * @return string pending, started, success or failure
     */
    public function status(string $taskId): string
    {
        return $this->request()->get("/v1/status/poll/{$taskId}")->throw()->json('task_status');
    }

    /**
     * @return array{status: string, errors: array<int, mixed>, document: array<string, mixed>}
     */
    public function result(string $taskId): array
    {
        return $this->request()->get("/v1/result/{$taskId}")->throw()->json();
    }

    /**
     * @return array<string, string>
     */
    private function routeOptions(DoclingRoute $route): array
    {
        return match ($route) {
            DoclingRoute::Standard => [
                'pipeline' => 'standard',
                'do_ocr' => 'false',
            ],
            DoclingRoute::Vlm => [
                'pipeline' => 'vlm',
                'vlm_pipeline_model_api' => json_encode([
                    'url' => config('services.docling.vlm_url'),
                    'params' => ['model' => config('services.docling.vlm_model'), 'max_tokens' => 4096],
                    'response_format' => 'markdown',
                    'prompt' => 'Convert this page to markdown. Keep the original language, do not translate.',
                    'timeout' => 300,
                    'concurrency' => 1,
                ]),
            ],
        };
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(config('services.docling.url'))->timeout(60);
    }
}
