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
     * @return list<array{text: string, chunk_index: int, headings: list<string>|null, page_numbers: list<int>|null, doc_items?: list<string>}>
     */
    public function chunk(string $doclingJson, string $fileName): array
    {
        return $this->withOrphanHeadings(
            json_decode($doclingJson, true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR),
            $this->hybridChunks($doclingJson, $fileName),
        );
    }

    /**
     * @return list<array{text: string, chunk_index: int, headings: list<string>|null, page_numbers: list<int>|null, doc_items?: list<string>}>
     */
    private function hybridChunks(string $doclingJson, string $fileName): array
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
     * Put back the headings HybridChunker drops: a heading followed straight by a heading of the same level has no text
     * of its own, so it is in no chunk and in no chunk's headings. The number and date of a service act live in such a heading.
     *
     * @param  array<string, mixed>  $doclingDocument
     * @param  list<array{text: string, chunk_index: int, headings: list<string>|null, page_numbers: list<int>|null, doc_items?: list<string>}>  $chunks
     * @return list<array{text: string, chunk_index: int, headings: list<string>|null, page_numbers: list<int>|null, doc_items?: list<string>}>
     */
    private function withOrphanHeadings(array $doclingDocument, array $chunks): array
    {
        $order = array_flip($this->readingOrder($doclingDocument, $doclingDocument['body'] ?? []));
        $covered = array_merge([], ...array_map(fn (array $chunk): array => $chunk['doc_items'] ?? [], $chunks));
        $keptHeadings = array_merge([], ...array_map(fn (array $chunk): array => $chunk['headings'] ?? [], $chunks));

        $orphans = array_filter($doclingDocument['texts'] ?? [], fn (array $item): bool => in_array($item['label'] ?? null, ['title', 'section_header'], true)
            && isset($order[$item['self_ref'] ?? ''])
            && ! in_array($item['self_ref'], $covered, true)
            && ! in_array($item['text'], $keptHeadings, true));
        usort($orphans, fn (array $left, array $right): int => $order[$left['self_ref']] <=> $order[$right['self_ref']]);

        $headingsByChunk = [];
        foreach ($orphans as $heading) {
            $target = $this->firstChunkAfter($chunks, $order, $order[$heading['self_ref']]);

            if ($target !== null) {
                $headingsByChunk[$target][] = $heading['text'];
            }
        }

        foreach ($headingsByChunk as $target => $headings) {
            $chunks[$target]['text'] = implode("\n", $headings)."\n".$chunks[$target]['text'];
            $chunks[$target]['headings'] = [...$headings, ...$chunks[$target]['headings'] ?? []];
        }

        return $chunks;
    }

    /**
     * References of the document items in reading order: the body tree walked depth first.
     *
     * @param  array<string, mixed>  $doclingDocument
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private function readingOrder(array $doclingDocument, array $node): array
    {
        $references = [];

        foreach ($node['children'] ?? [] as $child) {
            $reference = $child['$ref'];
            $references[] = $reference;

            // Ссылка вида #/groups/0 указывает на элемент документа; у групп и таблиц свои дети
            [, $collection, $index] = array_pad(explode('/', $reference), 3, null);
            $item = $doclingDocument[$collection][(int) $index] ?? null;

            if (is_array($item)) {
                $references = [...$references, ...$this->readingOrder($doclingDocument, $item)];
            }
        }

        return $references;
    }

    /**
     * The chunk that starts first after the position; headings are prepended to it.
     *
     * @param  list<array{doc_items?: list<string>}>  $chunks
     * @param  array<string, int>  $order
     */
    private function firstChunkAfter(array $chunks, array $order, int $position): ?int
    {
        $target = null;
        $targetStart = PHP_INT_MAX;

        foreach ($chunks as $index => $chunk) {
            $positions = array_map(fn (string $reference): int => $order[$reference] ?? PHP_INT_MAX, $chunk['doc_items'] ?? []);
            $start = $positions === [] ? PHP_INT_MAX : min($positions);

            if ($start > $position && $start < $targetStart) {
                $target = $index;
                $targetStart = $start;
            }
        }

        return $target;
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
                    // Таблицы — только markdown: docling разбирает их в таблицу документа, а LaTeX оставляет сплошным текстом.
                    // Пустой заголовок у таблицы без шапки: иначе первая строка данных станет заголовком и подписью всех значений
                    'prompt' => "Convert this page to markdown. Keep the original language, do not translate. Render tables as Markdown pipe tables; never use LaTeX or HTML. Most tables in forms have no header row: their first row is data, so start such a table with an empty header row, for example:\n| | |\n|---|---|\n| Name | John |\n| Phone | 123 |",
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
