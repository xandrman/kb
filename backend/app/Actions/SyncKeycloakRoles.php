<?php

namespace App\Actions;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SyncKeycloakRoles
{
    /**
     * Mirror the realm roles onto the local role set, creating ones not seen before.
     *
     * Every bearer request re-syncs, and an MCP host fires them in parallel, so the user
     * row is locked to serialize the diff; syncRoles() would detach everything and
     * re-insert, colliding on the pivot key.
     *
     * @param  array<int, string>  $realmRoles
     */
    public function handle(User $user, array $realmRoles): void
    {
        $roleIds = array_map(
            fn (string $role): int => Role::findOrCreate($role, 'web')->getKey(),
            $realmRoles,
        );

        DB::transaction(function () use ($user, $roleIds): void {
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            $user->roles()->sync($roleIds);
        });

        $user->unsetRelation('roles');
    }
}
