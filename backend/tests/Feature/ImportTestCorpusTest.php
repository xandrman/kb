<?php

namespace Tests\Feature;

use App\Enums\AccessLevel;
use App\Enums\DocumentStatus;
use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportTestCorpusTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private User $uploader;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        Queue::fake();

        $this->uploader = User::factory()->create();

        $this->directory = sys_get_temp_dir().'/corpus-'.uniqid();
        File::ensureDirectoryExists("{$this->directory}/synthetic");
        File::put("{$this->directory}/passport.pdf", "%PDF-1.4\npassport\n%%EOF\n");
        File::put("{$this->directory}/long.pdf", "%PDF-1.4\nlong\n%%EOF\n");
        File::put("{$this->directory}/synthetic/act.png", 'act scan');
        File::put("{$this->directory}/registry.json", json_encode([
            $this->entry('passport.pdf', 'Паспорт оборудования', 'public', inCorpus: true),
            $this->entry('long.pdf', 'Паспорт оборудования', 'public', inCorpus: false),
        ]));
        File::put("{$this->directory}/synthetic/manifest.json", json_encode([
            $this->entry('act.png', 'Сервисный акт', 'confidential'),
        ]));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_corpus_files_are_queued_with_their_metadata_and_files_out_of_the_corpus_are_left_out(): void
    {
        $this->import()->assertSuccessful();

        $this->assertSame(['act.png', 'passport.pdf'], Document::orderBy('original_name')->pluck('original_name')->all());

        $act = Document::where('original_name', 'act.png')->sole();
        $this->assertSame('SNR-UPS-LID-1500', $act->sku);
        $this->assertSame('Сервисный акт', $act->documentType->name);
        $this->assertSame(AccessLevel::Confidential, $act->access_level);
        $this->assertSame('Сервисный центр', $act->owner_department);
        $this->assertSame('2025-12-13', $act->document_date->toDateString());
        $this->assertSame(DocumentStatus::Pending, $act->status);
        $this->assertSame($this->uploader->id, $act->uploaded_by);

        Queue::assertPushed(ProcessDocument::class, 2);
    }

    public function test_a_second_run_skips_files_already_uploaded(): void
    {
        $this->import()->assertSuccessful();
        $this->import()->expectsOutputToContain('Поставлено в очередь: 0 из 2')->assertSuccessful();

        $this->assertSame(2, Document::count());
    }

    public function test_a_document_type_missing_in_the_system_stops_the_import_before_any_upload(): void
    {
        DocumentType::where('name', 'Сервисный акт')->update(['is_active' => false]);

        $this->expectExceptionMessage('Сервисный акт');

        try {
            $this->import();
        } finally {
            $this->assertSame(0, Document::count());
        }
    }

    private function import(): mixed
    {
        return $this->artisan('testdata:import', [
            '--uploader' => $this->uploader->email,
            '--registry' => "{$this->directory}/registry.json",
            '--manifest' => "{$this->directory}/synthetic/manifest.json",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $filename, string $documentType, string $accessLevel, bool $inCorpus = true): array
    {
        return [
            'filename' => $filename,
            'sku' => 'SNR-UPS-LID-1500',
            'document_type' => $documentType,
            'access_level' => $accessLevel,
            'owner_department' => 'Сервисный центр',
            'document_date' => '2025-12-13',
            'in_corpus' => $inCorpus,
        ];
    }
}
