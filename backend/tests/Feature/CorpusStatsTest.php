<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Filament\Widgets\CorpusStatsOverview;
use App\Models\Document;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CorpusStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->create());
    }

    public function test_the_dashboard_counts_documents_by_processing_stage(): void
    {
        Document::factory()->count(3)->create([
            'status' => DocumentStatus::Processed,
            'size' => 1024 * 1024,
            'personal_data_count' => 4,
            'docling_processing_time' => 60,
            'indexing_time' => 30,
            'graph_time' => 90,
        ]);
        Document::factory()->create(['status' => DocumentStatus::Pending, 'size' => 1024 * 1024]);
        Document::factory()->create(['status' => DocumentStatus::Extracting, 'size' => 1024 * 1024]);
        Document::factory()->create(['status' => DocumentStatus::Failed, 'size' => 1024 * 1024]);
        Document::factory()->create(['status' => DocumentStatus::Duplicate, 'size' => 100 * 1024 * 1024, 'personal_data_count' => 50]);

        Livewire::test(CorpusStatsOverview::class)
            ->assertSeeInOrder(['Документов', '6'])
            ->assertSeeInOrder(['Обработано', '3'])
            ->assertSeeInOrder(['В очереди и обработке', '2'])
            ->assertSeeInOrder(['С ошибкой', '1'])
            ->assertSeeInOrder(['Объём файлов', '6,0 МБ'])
            ->assertSeeInOrder(['Замаскировано ПДн', '12'])
            ->assertSeeInOrder(['Среднее время обработки', '3,0 мин']);
    }

    public function test_an_empty_corpus_has_no_processing_time(): void
    {
        Livewire::test(CorpusStatsOverview::class)
            ->assertSeeInOrder(['Документов', '0'])
            ->assertSeeInOrder(['Объём файлов', '0,0 МБ'])
            ->assertSeeInOrder(['Среднее время обработки', '—']);
    }

    public function test_the_admin_dashboard_shows_the_corpus_stats(): void
    {
        Role::findOrCreate('kb-admin', 'web');
        $this->actingAs(User::factory()->create()->assignRole('kb-admin'));

        $this->get('/admin')
            ->assertOk()
            ->assertSeeLivewire(CorpusStatsOverview::class);
    }
}
