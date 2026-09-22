<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Http\Request;
use SocialiteProviders\Manager\OAuth2\User as SocialiteUser;

class AuthenticateWithKeycloakBearer
{
    public function __construct(
        private VerifyKeycloakAccessToken $verifyKeycloakAccessToken,
        private SyncKeycloakUser $syncKeycloakUser,
    ) {}

    /**
     * Resolve the user behind the bearer token the MCP host forwards on the user's behalf.
     */
    public function handle(Request $request): ?User
    {
        $token = $request->bearerToken();

        if (blank($token)) {
            return null;
        }

        $claims = $this->verifyKeycloakAccessToken->handle($token);

        if ($claims === null || blank($claims['email'] ?? null)) {
            return null;
        }

        $keycloakUser = (new SocialiteUser)->setRaw($claims)->map([
            'id' => $claims['sub'],
            'nickname' => $claims['preferred_username'] ?? null,
            'name' => $claims['name'] ?? null,
            'email' => $claims['email'],
        ]);

        return $this->syncKeycloakUser->handle($keycloakUser);
    }
}
