<?php

namespace Tests\Feature;

use App\Enums\AccessLevel;
use App\Enums\DocumentStatus;
use App\Filament\Resources\Documents\Pages\CreateDocument;
use App\Filament\Resources\Documents\Pages\ListDocuments;
use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class DocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    private const string PDF_CONTENT = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n";

    private DocumentType $documentType;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        Queue::fake();

        $this->documentType = DocumentType::factory()->create();

        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->create());
    }

    public function test_an_uploaded_file_is_stored_under_its_digest_and_queued_for_processing(): void
    {
        $digest = hash('sha256', self::PDF_CONTENT);

        $this->uploadDocument()
            ->assertHasNoFormErrors()
            ->assertNotified('Документ поставлен в очередь на обработку')
            ->assertRedirect();

        Storage::disk('documents')->assertExists('raw/'.substr($digest, 0, 2).'/'.$digest);
        Storage::disk('documents')->assertDirectoryEmpty('tmp');

        $document = Document::sole();
        $this->assertSame($digest, $document->digest);
        $this->assertSame('manual.pdf', $document->original_name);
        $this->assertSame('application/pdf', $document->mime_type);
        $this->assertTrue($document->documentType->is($this->documentType));
        $this->assertSame(AccessLevel::Confidential, $document->access_level);
        $this->assertSame(DocumentStatus::Pending, $document->status);
        $this->assertSame(auth()->id(), $document->uploaded_by);

        Queue::assertPushedOn('documents', ProcessDocument::class, fn (ProcessDocument $job): bool => $job->document->is($document));
    }

    public function test_a_file_uploaded_again_is_registered_as_a_duplicate_without_reprocessing(): void
    {
        // Уведомление первой загрузки забирается сразу: иначе оно остаётся в сессии и заслоняет второе
        $this->uploadDocument()->assertNotified('Документ поставлен в очередь на обработку');
        $this->uploadDocument(['sku' => 'SKU-00002'])
            ->assertHasNoFormErrors()
            ->assertNotified('Такой файл уже загружен');

        $this->assertSame(2, Document::count());
        $this->assertSame(1, Document::query()->distinct()->count('digest'));
        $this->assertSame(DocumentStatus::Duplicate, Document::firstWhere('sku', 'SKU-00002')->status);
        Queue::assertPushed(ProcessDocument::class, 1);
    }

    public function test_the_metadata_required_by_the_srs_must_be_filled(): void
    {
        $this->uploadDocument([
            'sku' => null,
            'document_type_id' => null,
            'access_level' => null,
            'owner_department' => null,
        ])->assertHasFormErrors([
            'sku' => 'required',
            'document_type_id' => 'required',
            'access_level' => 'required',
            'owner_department' => 'required',
        ]);

        $this->assertSame(0, Document::count());
        Queue::assertNothingPushed();
    }

    public function test_a_deactivated_document_type_cannot_be_chosen_for_a_new_upload(): void
    {
        $retiredType = DocumentType::factory()->inactive()->create();

        $this->uploadDocument(['document_type_id' => $retiredType->getKey()])
            ->assertHasFormErrors(['document_type_id']);

        $this->assertSame(0, Document::count());
    }

    public function test_only_pdf_png_and_jpeg_files_are_accepted(): void
    {
        $this->uploadDocument(['file' => UploadedFile::fake()->createWithContent('notes.txt', 'plain text')])
            ->assertHasFormErrors(['file']);

        $this->assertSame(0, Document::count());
    }

    public function test_the_document_list_shows_the_time_of_each_stage(): void
    {
        Document::factory()->create(['docling_processing_time' => 8.7, 'indexing_time' => 90.4, 'graph_time' => 3900.0, 'personal_data_count' => 17]);

        Livewire::test(ListDocuments::class)
            ->assertSeeInOrder(['Извлечение', 'Чанкование', 'Фрагменты', 'ПДн'])
            ->assertSeeInOrder(['8,7 с', '1 мин 30 с', '1 ч 5 мин', '17']);
    }

    public function test_a_failed_document_can_be_processed_again(): void
    {
        $document = Document::factory()->create([
            'status' => DocumentStatus::Failed,
            'docling_task_id' => 'c41cb5ef-92c5-4fd1-b02c-0cd4b431def1',
            'error' => 'docling не разбил документ на чанки',
            'docling_processing_time' => 8.7,
            'indexing_time' => 3.2,
            'graph_time' => 45.0,
            'personal_data_count' => 17,
        ]);

        Livewire::test(ListDocuments::class)
            ->callAction(TestAction::make('reprocess')->table($document))
            ->assertHasNoActionErrors();

        $document->refresh();
        $this->assertSame(DocumentStatus::Pending, $document->status);
        $this->assertNull($document->docling_task_id);
        $this->assertNull($document->error);
        $this->assertNull($document->docling_processing_time);
        $this->assertNull($document->indexing_time);
        $this->assertNull($document->graph_time);
        $this->assertSame(0, $document->personal_data_count);
        Queue::assertPushedOn('documents', ProcessDocument::class, fn (ProcessDocument $job): bool => $job->document->is($document));
    }

    public function test_documents_in_progress_cannot_be_processed_again(): void
    {
        $inProgress = collect([DocumentStatus::Pending, DocumentStatus::Extracting, DocumentStatus::Indexed, DocumentStatus::Duplicate])
            ->map(fn (DocumentStatus $status): Document => Document::factory()->create(['status' => $status]));
        $processed = Document::factory()->create(['status' => DocumentStatus::Processed]);

        $page = Livewire::test(ListDocuments::class)->assertActionVisible(TestAction::make('reprocess')->table($processed));
        $inProgress->each(fn (Document $document) => $page->assertActionHidden(TestAction::make('reprocess')->table($document)));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function uploadDocument(array $overrides = []): Testable
    {
        return Livewire::test(CreateDocument::class)
            ->fillForm([
                'file' => UploadedFile::fake()->createWithContent('manual.pdf', self::PDF_CONTENT),
                'sku' => 'SKU-00001',
                'document_type_id' => $this->documentType->getKey(),
                'access_level' => AccessLevel::Confidential->value,
                'owner_department' => 'Сервис',
                'document_date' => '2026-09-01',
                ...$overrides,
            ])
            ->call('create');
    }
}
