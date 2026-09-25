<?php

namespace Tests\Feature;

use App\Enums\AccessLevel;
use App\Filament\Resources\Roles\Pages\ManageRoles;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RoleAccessLevelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->create());
    }

    public function test_an_administrator_grants_a_keycloak_role_an_access_level(): void
    {
        $role = Role::findOrCreate('service-engineer', 'web');

        Livewire::test(ManageRoles::class)
            ->assertCanSeeTableRecords([$role])
            ->assertActionDoesNotExist(TestAction::make(DeleteAction::class)->table($role))
            ->callAction(TestAction::make(EditAction::class)->table($role), ['access_level' => AccessLevel::Internal->value])
            ->assertHasNoFormErrors();

        $this->assertSame(AccessLevel::Internal, $role->refresh()->access_level);
    }

    public function test_the_choice_lists_what_each_option_opens_and_an_unset_role_shows_public(): void
    {
        $role = Role::findOrCreate('viewer', 'web');

        Livewire::test(ManageRoles::class)
            ->assertTableColumnStateSet('access_level', [AccessLevel::Public], $role)
            ->mountAction(TestAction::make(EditAction::class)->table($role))
            ->assertSchemaStateSet(['access_level' => AccessLevel::Public->value])
            ->assertFormFieldExists('access_level', fn ($field): bool => $field->getOptions() === [
                'public' => 'Общедоступные',
                'internal' => 'Общедоступные и служебные',
                'confidential' => 'Общедоступные, служебные и конфиденциальные',
            ]);
    }

    public function test_a_role_shows_every_level_it_opens(): void
    {
        $role = Role::findOrCreate('security', 'web');
        $role->update(['access_level' => AccessLevel::Confidential]);

        Livewire::test(ManageRoles::class)
            ->assertTableColumnStateSet('access_level', [AccessLevel::Public, AccessLevel::Internal, AccessLevel::Confidential], $role)
            ->assertSeeInOrder(['Общедоступный', 'Служебный', 'Конфиденциальный']);
    }

    public function test_a_role_can_be_configured_before_anyone_signs_in_with_it(): void
    {
        Livewire::test(ManageRoles::class)
            ->callAction(CreateAction::class, ['name' => 'security-officer', 'access_level' => AccessLevel::Confidential->value])
            ->assertHasNoFormErrors();

        $role = Role::findByName('security-officer', 'web');
        $this->assertSame(AccessLevel::Confidential, $role->access_level);
    }

    public function test_the_user_clearance_is_the_strictest_level_of_their_roles(): void
    {
        Role::findOrCreate('engineer', 'web')->update(['access_level' => AccessLevel::Internal]);
        Role::findOrCreate('security', 'web')->update(['access_level' => AccessLevel::Confidential]);
        Role::findOrCreate('viewer', 'web');

        $this->assertSame(AccessLevel::Confidential, User::factory()->create()->assignRole('engineer', 'security', 'viewer')->clearance());
        $this->assertSame(AccessLevel::Internal, User::factory()->create()->assignRole('engineer')->clearance());
    }

    public function test_a_user_without_granted_roles_sees_only_public_documents(): void
    {
        Role::findOrCreate('viewer', 'web');

        $this->assertSame(AccessLevel::Public, User::factory()->create()->assignRole('viewer')->clearance());
        $this->assertSame(AccessLevel::Public, User::factory()->create()->clearance());
    }
}
