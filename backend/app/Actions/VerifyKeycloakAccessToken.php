<?php

namespace App\Actions;

class VerifyKeycloakAccessToken
{
    public function __construct(
        private DecodeKeycloakToken $decodeKeycloakToken,
        private RevokeKeycloakSession $revokeKeycloakSession,
    ) {}

    /**
     * Return the claims of a realm access token issued for this application, or null when it must be rejected.
     *
     * @return array<string, mixed>|null
     */
    public function handle(string $token): ?array
    {
        $claims = $this->decodeKeycloakToken->handle($token);

        return $claims !== null && $this->isIssuedForThisApplication($claims) ? $claims : null;
    }

    /**
     * Signature, lifetime and issuer are checked on decoding; the rest of the contract is checked here.
     * An id_token carries the same audience, so the token type is what tells the two apart.
     * The session id is required: without it a back-channel logout could not reach the token.
     *
     * @param  array<string, mixed>  $claims
     */
    private function isIssuedForThisApplication(array $claims): bool
    {
        return isset($claims['exp'])
            && filled($claims['sub'] ?? null)
            && ($claims['typ'] ?? null) === 'Bearer'
            && in_array(config('services.keycloak.client_id'), (array) ($claims['aud'] ?? []), true)
            && is_string($claims['sid'] ?? null)
            && ! $this->revokeKeycloakSession->isRevoked($claims['sid']);
    }
}
