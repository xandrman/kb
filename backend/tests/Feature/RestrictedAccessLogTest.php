<?php

namespace Tests\Feature;

use App\Enums\AccessLevel;
use App\Filament\Resources\RestrictedChunkReads\Pages\ListRestrictedChunkReads;
use App\Filament\Resources\RestrictedChunkReads\RestrictedChunkReadResource;
use App\Models\Document;
use App\Models\RestrictedChunkRead;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RestrictedAccessLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->create());
    }

    public function test_the_log_lists_reads_newest_first_with_user_and_document(): void
    {
        $older = RestrictedChunkRead::factory()->create(['created_at' => now()->subHour()]);
        $newer = RestrictedChunkRead::factory()->create([
            'user_id' => User::factory()->create(['name' => 'Инженер Петров']),
            'document_id' => Document::factory()->create(['original_name' => 'Договор поставки.pdf']),
            'access_level' => AccessLevel::Internal,
            'page_numbers' => [4, 5],
        ]);

        Livewire::test(ListRestrictedChunkReads::class)
            ->assertCanSeeTableRecords([$newer, $older], inOrder: true)
            ->assertSee(['Инженер Петров', 'Договор поставки.pdf', 'Служебный', '4, 5']);
    }

    public function test_the_log_is_filtered_by_user(): void
    {
        $engineer = User::factory()->create();
        $engineersRead = RestrictedChunkRead::factory()->create(['user_id' => $engineer]);
        $othersRead = RestrictedChunkRead::factory()->create();

        Livewire::test(ListRestrictedChunkReads::class)
            ->filterTable('user', $engineer->id)
            ->assertCanSeeTableRecords([$engineersRead])
            ->assertCanNotSeeTableRecords([$othersRead]);
    }

    public function test_the_log_cannot_be_changed_from_the_interface(): void
    {
        $read = RestrictedChunkRead::factory()->create();

        Livewire::test(ListRestrictedChunkReads::class)->assertActionDoesNotExist(CreateAction::class);
        $this->assertFalse(RestrictedChunkReadResource::canCreate());
        $this->assertFalse(RestrictedChunkReadResource::canEdit($read));
        $this->assertFalse(RestrictedChunkReadResource::canDelete($read));
    }
}
