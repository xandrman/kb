<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use SocialiteProviders\Manager\OAuth2\User as SocialiteUser;

class AuthenticateWithKeycloak
{
    public function __construct(private SyncKeycloakUser $syncKeycloakUser) {}

    /**
     * Exchange the authorization code for an identity and open a local session.
     *
     * @throws InvalidStateException
     */
    public function handle(): User
    {
        /** @var SocialiteUser $keycloakUser */
        $keycloakUser = Socialite::driver('keycloak')->user();

        if (blank($keycloakUser->getEmail())) {
            abort(403, 'Keycloak account has no email address.');
        }

        $user = $this->syncKeycloakUser->handle($keycloakUser);

        Auth::login($user, remember: false);

        return $user;
    }
}
