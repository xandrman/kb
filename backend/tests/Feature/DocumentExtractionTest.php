<?php

namespace Tests\Feature;

use App\Enums\DoclingRoute;
use App\Enums\DocumentStatus;
use App\Jobs\IndexDocument;
use App\Jobs\PollExtraction;
use App\Jobs\ProcessDocument;
use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DocumentExtractionTest extends TestCase
{
    use RefreshDatabase;

    private const string DOCLING_URL = 'http://docling.test';

    private const string TASK_ID = 'c41cb5ef-92c5-4fd1-b02c-0cd4b431def1';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        Queue::fake();

        config([
            'services.docling.url' => self::DOCLING_URL,
            'services.docling.vlm_url' => 'http://vllm.test/v1/chat/completions',
            'services.docling.vlm_model' => 'default',
        ]);
    }

    public function test_a_pdf_is_sent_to_docling_by_its_text_layer_and_polling_starts(): void
    {
        Http::fake(['docling.test/v1/convert/file/async' => Http::response(['task_id' => self::TASK_ID, 'task_status' => 'pending'])]);
        $this->fakeTextLayer('Руководство пользователя источника бесперебойного питания', 'Technical specifications of the device');
        $document = $this->storedDocument('%PDF-1.4 manual', 'application/pdf');

        app()->call([new ProcessDocument($document), 'handle']);

        Http::assertSent(fn (Request $request): bool => $request->url() === self::DOCLING_URL.'/v1/convert/file/async'
            && $this->multipartField($request, 'pipeline') === 'standard'
            && $this->multipartField($request, 'do_ocr') === 'false'
            && $this->multipartField($request, 'to_formats') === 'json'
            && $this->multipartFileName($request) === $document->original_name);

        $document->refresh();
        $this->assertSame(DocumentStatus::Extracting, $document->status);
        $this->assertSame(DoclingRoute::Standard, $document->route);
        $this->assertSame(self::TASK_ID, $document->docling_task_id);
        Queue::assertPushedOn('documents', PollExtraction::class, fn (PollExtraction $job): bool => $job->document->is($document));
    }

    public function test_an_image_is_sent_to_the_vlm_pipeline(): void
    {
        Http::fake(['docling.test/v1/convert/file/async' => Http::response(['task_id' => self::TASK_ID, 'task_status' => 'pending'])]);
        $document = $this->storedDocument('jpeg bytes', 'image/jpeg');

        app()->call([new ProcessDocument($document), 'handle']);

        Http::assertSent(function (Request $request): bool {
            $vlm = json_decode((string) $this->multipartField($request, 'vlm_pipeline_model_api'), true);

            return $this->multipartField($request, 'pipeline') === 'vlm'
                && $vlm['url'] === 'http://vllm.test/v1/chat/completions'
                && $vlm['params']['model'] === 'default'
                && str_contains($vlm['prompt'], 'Render tables as Markdown pipe tables; never use LaTeX or HTML.')
                && str_contains($vlm['prompt'], 'start such a table with an empty header row');
        });

        $this->assertSame(DoclingRoute::Vlm, $document->refresh()->route);
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function pdfsFailingTheTextLayerGate(): array
    {
        $text = 'Руководство пользователя источника бесперебойного питания';

        return [
            'scan without a text layer' => [['', '', '']],
            'font without ToUnicode' => [['Ðóêîâîäñòâî ïîëüçîâàòåëÿ èñòî÷íèêà áåñïåðåáîéíîãî ïèòàíèÿ']],
            'text on fewer than half of the pages' => [[$text, '', '12']],
        ];
    }

    /**
     * @param  list<string>  $pages
     */
    #[DataProvider('pdfsFailingTheTextLayerGate')]
    public function test_a_pdf_failing_the_text_layer_gate_is_sent_to_the_vlm_pipeline(array $pages): void
    {
        Http::fake(['docling.test/v1/convert/file/async' => Http::response(['task_id' => self::TASK_ID, 'task_status' => 'pending'])]);
        $this->fakeTextLayer(...$pages);
        $document = $this->storedDocument('%PDF-1.4 scan', 'application/pdf');

        app()->call([new ProcessDocument($document), 'handle']);

        Http::assertSent(fn (Request $request): bool => $this->multipartField($request, 'pipeline') === 'vlm');
        $this->assertSame(DoclingRoute::Vlm, $document->refresh()->route);
    }

    public function test_a_pdf_poppler_cannot_read_is_sent_to_the_vlm_pipeline(): void
    {
        Http::fake(['docling.test/v1/convert/file/async' => Http::response(['task_id' => self::TASK_ID, 'task_status' => 'pending'])]);
        Process::fake(['*' => Process::result(errorOutput: 'Syntax Error: Couldn\'t find trailer dictionary', exitCode: 1)]);
        $document = $this->storedDocument('%PDF-1.4 broken', 'application/pdf');

        app()->call([new ProcessDocument($document), 'handle']);

        $this->assertSame(DoclingRoute::Vlm, $document->refresh()->route);
    }

    public function test_a_blank_back_page_keeps_a_pdf_on_the_standard_pipeline(): void
    {
        Http::fake(['docling.test/v1/convert/file/async' => Http::response(['task_id' => self::TASK_ID, 'task_status' => 'pending'])]);
        $this->fakeTextLayer('Руководство пользователя источника бесперебойного питания', '');
        $document = $this->storedDocument('%PDF-1.4 leaflet', 'application/pdf');

        app()->call([new ProcessDocument($document), 'handle']);

        Http::assertSent(fn (Request $request): bool => $this->multipartField($request, 'pipeline') === 'standard');
        $this->assertSame(DoclingRoute::Standard, $document->refresh()->route);
    }

    public function test_a_corrupted_original_fails_the_document_without_calling_docling(): void
    {
        Http::fake();
        $document = $this->storedDocument('%PDF-1.4 manual', 'application/pdf');
        $document->update(['digest' => hash('sha256', 'other content')]);
        Storage::disk('documents')->put($this->rawPath($document->digest), '%PDF-1.4 manual');

        $job = (new ProcessDocument($document))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $job->assertFailed();
        Http::assertNothingSent();
        Queue::assertNotPushed(PollExtraction::class);
    }

    public function test_polling_is_repeated_while_docling_is_still_working(): void
    {
        Http::fake(['docling.test/v1/status/poll/*' => Http::response(['task_id' => self::TASK_ID, 'task_status' => 'started'])]);
        $document = $this->extractingDocument();

        $job = (new PollExtraction($document))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $job->assertReleased(delay: 15);
        $this->assertSame(DocumentStatus::Extracting, $document->refresh()->status);
    }

    public function test_a_finished_extraction_is_stored_under_the_original_digest(): void
    {
        $doclingDocument = ['schema_name' => 'DoclingDocument', 'name' => 'manual', 'texts' => [['text' => 'Руководство пользователя']]];
        Http::fake([
            'docling.test/v1/status/poll/*' => Http::response(['task_id' => self::TASK_ID, 'task_status' => 'success']),
            'docling.test/v1/result/*' => Http::response(['status' => 'success', 'errors' => [], 'processing_time' => 8.7, 'document' => ['json_content' => $doclingDocument]]),
        ]);
        $document = $this->extractingDocument();

        app()->call([new PollExtraction($document), 'handle']);

        $path = 'extracted/'.substr($document->digest, 0, 2).'/'.$document->digest.'.json';
        Storage::disk('documents')->assertExists($path);
        $this->assertSame($doclingDocument, json_decode(Storage::disk('documents')->get($path), true));
        $this->assertSame([$path], Storage::disk('documents')->allFiles('extracted'));
        $document->refresh();
        $this->assertSame(DocumentStatus::Extracted, $document->status);
        $this->assertSame(8.7, $document->docling_processing_time);
        Queue::assertPushedOn('documents', IndexDocument::class, fn (IndexDocument $job): bool => $job->document->is($document));
    }

    public function test_a_uint64_binary_hash_is_stored_without_losing_precision(): void
    {
        Http::fake([
            'docling.test/v1/status/poll/*' => Http::response(['task_id' => self::TASK_ID, 'task_status' => 'success']),
            'docling.test/v1/result/*' => Http::response('{"status":"success","errors":[],"processing_time":8.7,"document":{"json_content":{"schema_name":"DoclingDocument","origin":{"binary_hash":15376209090199646208}}}}'),
        ]);
        $document = $this->extractingDocument();

        app()->call([new PollExtraction($document), 'handle']);

        $stored = Storage::disk('documents')->get('extracted/'.substr($document->digest, 0, 2).'/'.$document->digest.'.json');
        $this->assertStringContainsString('"binary_hash":"15376209090199646208"', $stored);
    }

    public function test_a_docling_failure_is_shown_on_the_document(): void
    {
        Http::fake(['docling.test/v1/status/poll/*' => Http::response(['task_id' => self::TASK_ID, 'task_status' => 'failure'])]);
        $document = $this->extractingDocument();

        $job = (new PollExtraction($document))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);
        $job->failed(new RuntimeException('docling не обработал документ'));

        $job->assertFailed();
        $document->refresh();
        $this->assertSame(DocumentStatus::Failed, $document->status);
        $this->assertSame('docling не обработал документ', $document->error);
    }

    private function storedDocument(string $content, string $mimeType): Document
    {
        $digest = hash('sha256', $content);
        Storage::disk('documents')->put($this->rawPath($digest), $content);

        return Document::factory()->create(['digest' => $digest, 'mime_type' => $mimeType]);
    }

    /**
     * pdftotext output for the given pages: every page ends with a form feed.
     */
    private function fakeTextLayer(string ...$pages): void
    {
        Process::fake(['*' => Process::result(implode('', array_map(fn (string $page): string => $page."\f", $pages)))]);
    }

    private function extractingDocument(): Document
    {
        return Document::factory()->create([
            'status' => DocumentStatus::Extracting,
            'docling_task_id' => self::TASK_ID,
        ]);
    }

    private function rawPath(string $digest): string
    {
        return 'raw/'.substr($digest, 0, 2).'/'.$digest;
    }

    private function multipartFileName(Request $request): ?string
    {
        return collect($request->data())->firstWhere('name', 'files')['filename'] ?? null;
    }

    private function multipartField(Request $request, string $name): ?string
    {
        foreach ($request->data() as $part) {
            if (($part['name'] ?? null) === $name) {
                return (string) $part['contents'];
            }
        }

        return null;
    }
}
