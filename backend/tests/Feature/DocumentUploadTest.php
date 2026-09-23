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

    public function test_the_document_list_shows_the_extraction_time(): void
    {
        Document::factory()->create(['docling_processing_time' => 8.7]);

        Livewire::test(ListDocuments::class)->assertSee('8,7 с');
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
