<?php

namespace App\Actions;

class VerifyKeycloakLogoutToken
{
    private const string BACKCHANNEL_LOGOUT_EVENT = 'http://schemas.openid.net/event/backchannel-logout';

    public function __construct(private DecodeKeycloakToken $decodeKeycloakToken) {}

    /**
     * Return the claims of a back-channel logout token for this application, or null when it must be rejected.
     *
     * @return array<string, mixed>|null
     */
    public function handle(string $token): ?array
    {
        $claims = $this->decodeKeycloakToken->handle($token);

        return $claims !== null && $this->isLogoutForThisApplication($claims) ? $claims : null;
    }

    /**
     * OpenID Connect Back-Channel Logout 1.0, 2.6. Keycloak addresses the token to the client that holds
     * the session, so the MCP host must get its tokens through this application's client. The logout event
     * and the absent nonce keep access and id tokens from passing as a logout token; sid is required
     * because revocation is per session.
     *
     * @param  array<string, mixed>  $claims
     */
    private function isLogoutForThisApplication(array $claims): bool
    {
        return isset($claims['iat'])
            && ! array_key_exists('nonce', $claims)
            && is_array($claims['events'][self::BACKCHANNEL_LOGOUT_EVENT] ?? null)
            && in_array(config('services.keycloak.client_id'), (array) ($claims['aud'] ?? []), true)
            && is_string($claims['sid'] ?? null)
            && filled($claims['sid']);
    }
}
