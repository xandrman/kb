<?php

namespace App\Services;

use App\Enums\DoclingRoute;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

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
     * @return array{status: string, errors: array<int, mixed>, processing_time: float, document: array<string, mixed>}
     */
    public function result(string $taskId): array
    {
        $body = $this->request()->get("/v1/result/{$taskId}")->throw()->body();

        // binary_hash — uint64: выше PHP_INT_MAX json_decode делает float, и после сохранения docling отвергает документ как невалидный.
        // Строкой число сохраняется точно, а docling принимает его и в таком виде
        return json_decode($body, true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
    }

    /**
     * Split a stored DoclingDocument with HybridChunker, without converting the original again (ADR-0012).
     *
     * @return list<array{text: string, chunk_index: int, headings: list<string>|null, page_numbers: list<int>|null}>
     */
    public function chunk(string $doclingJson, string $fileName): array
    {
        $response = $this->request()
            ->attach('files', $doclingJson, $fileName)
            ->post('/v1/chunk/hybrid/file', [
                'convert_from_formats' => 'json_docling',
                'chunking_tokenizer' => config('services.docling.chunk_tokenizer'),
                'chunking_max_tokens' => (string) config('services.docling.chunk_max_tokens'),
            ])
            ->throw();

        // Ошибка разбора приходит с кодом 200: статус есть только у документа в теле ответа
        foreach ($response->json('documents') as $result) {
            if ($result['status'] !== 'success') {
                throw new RuntimeException('docling не разбил документ на чанки: '.json_encode($result['errors'], JSON_UNESCAPED_UNICODE));
            }
        }

        return $response->json('chunks');
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
