<?php

namespace App\Actions;

use App\Models\Role;
use App\Models\User;
use Spatie\Permission\Contracts\Role as RoleContract;

class SyncKeycloakRoles
{
    /**
     * Mirror the realm roles onto the local role set, creating ones not seen before.
     *
     * @param  array<int, string>  $realmRoles
     */
    public function handle(User $user, array $realmRoles): void
    {
        $roles = array_map(
            fn (string $role): RoleContract => Role::findOrCreate($role, 'web'),
            $realmRoles,
        );

        $user->syncRoles($roles);
    }
}
