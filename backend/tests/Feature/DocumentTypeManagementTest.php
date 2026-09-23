<?php

namespace Tests\Feature;

use App\Filament\Resources\DocumentTypes\Pages\ManageDocumentTypes;
use App\Models\DocumentType;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DocumentTypeManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->create());
    }

    public function test_the_srs_document_kinds_are_seeded_as_active_types(): void
    {
        $this->assertEqualsCanonicalizing(
            ['Руководство', 'Инструкция', 'Контракт', 'Сервисный акт', 'Паспорт оборудования'],
            DocumentType::active()->pluck('name')->all(),
        );
    }

    public function test_an_administrator_adds_a_document_type(): void
    {
        Livewire::test(ManageDocumentTypes::class)
            ->callAction(CreateAction::class, ['name' => 'Гарантийный талон'])
            ->assertHasNoFormErrors();

        $this->assertTrue(DocumentType::firstWhere('name', 'Гарантийный талон')->is_active);
    }

    public function test_a_type_name_must_be_unique(): void
    {
        Livewire::test(ManageDocumentTypes::class)
            ->callAction(CreateAction::class, ['name' => 'Контракт'])
            ->assertHasFormErrors(['name' => 'unique']);
    }

    public function test_an_administrator_deactivates_a_type_instead_of_deleting_it(): void
    {
        $type = DocumentType::factory()->create();

        Livewire::test(ManageDocumentTypes::class)
            ->assertActionDoesNotExist(TestAction::make(DeleteAction::class)->table($type))
            ->callAction(TestAction::make(EditAction::class)->table($type), ['is_active' => false])
            ->assertHasNoFormErrors();

        $this->assertFalse($type->refresh()->is_active);
    }
}
