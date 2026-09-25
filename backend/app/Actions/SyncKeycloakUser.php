<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Arr;
use SocialiteProviders\Manager\OAuth2\User as SocialiteUser;

class SyncKeycloakUser
{
    public function __construct(private SyncKeycloakRoles $syncKeycloakRoles) {}

    /**
     * Project the Keycloak identity onto a local record, matched on the subject.
     */
    public function handle(SocialiteUser $keycloakUser): User
    {
        $user = User::firstWhere('keycloak_id', $keycloakUser->getId()) ?? new User;

        $user->fill([
            'keycloak_id' => $keycloakUser->getId(),
            'name' => $keycloakUser->getName() ?: $keycloakUser->getNickname(),
            'email' => $keycloakUser->getEmail(),
            'last_login_at' => now(),
        ]);

        if (Arr::get($keycloakUser->getRaw(), 'email_verified') && blank($user->email_verified_at)) {
            $user->email_verified_at = now();
        }

        $user->save();

        $this->syncKeycloakRoles->handle($user, Arr::get($keycloakUser->getRaw(), 'realm_access.roles', []));

        return $user;
    }
}
