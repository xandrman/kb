<?php

namespace App\Actions;

use DomainException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use UnexpectedValueException;

class VerifyKeycloakAccessToken
{
    private const string JWKS_CACHE_KEY = 'keycloak.jwks';

    private const int JWKS_CACHE_SECONDS = 3600;

    /**
     * Marker that throttles forced JWKS refetches, so tokens with made-up key ids cannot hammer Keycloak.
     */
    private const string JWKS_REFRESH_MARKER_KEY = 'keycloak.jwks.refreshed';

    private const int JWKS_REFRESH_COOLDOWN_SECONDS = 60;

    /**
     * Return the claims of a realm access token issued for this application, or null when it must be rejected.
     *
     * @return array<string, mixed>|null
     */
    public function handle(string $token): ?array
    {
        try {
            $claims = json_decode(
                (string) json_encode(JWT::decode($token, $this->signingKeys($this->keyId($token)))),
                true,
            );
        } catch (UnexpectedValueException|DomainException|InvalidArgumentException) {
            return null;
        }

        return $this->isIssuedForThisApplication($claims) ? $claims : null;
    }

    /**
     * Signature and lifetime are checked by JWT::decode; the rest of the contract is checked here.
     * An id_token carries the same audience, so the token type is what tells the two apart.
     *
     * @param  array<string, mixed>  $claims
     */
    private function isIssuedForThisApplication(array $claims): bool
    {
        return isset($claims['exp'])
            && filled($claims['sub'] ?? null)
            && ($claims['typ'] ?? null) === 'Bearer'
            && ($claims['iss'] ?? null) === $this->issuer()
            && in_array(config('services.keycloak.client_id'), (array) ($claims['aud'] ?? []), true);
    }

    private function keyId(string $token): ?string
    {
        $header = json_decode(JWT::urlsafeB64Decode(explode('.', $token)[0]), true);

        return is_array($header) && is_string($header['kid'] ?? null) ? $header['kid'] : null;
    }

    /**
     * Keycloak rotates keys under a new id, so an unknown id triggers one refetch per cooldown window.
     *
     * @return array<string, Key>
     */
    private function signingKeys(?string $keyId): array
    {
        $jwks = Cache::remember(self::JWKS_CACHE_KEY, self::JWKS_CACHE_SECONDS, fn (): array => $this->fetchJwks());

        $isKnownKey = collect($jwks['keys'])->contains('kid', $keyId);

        if (! $isKnownKey && Cache::add(self::JWKS_REFRESH_MARKER_KEY, true, self::JWKS_REFRESH_COOLDOWN_SECONDS)) {
            $jwks = $this->fetchJwks();
            Cache::put(self::JWKS_CACHE_KEY, $jwks, self::JWKS_CACHE_SECONDS);
        }

        return JWK::parseKeySet($jwks);
    }

    /**
     * Only signature keys are kept: the realm also publishes an RSA-OAEP encryption key.
     *
     * @return array{keys: list<array<string, mixed>>}
     */
    private function fetchJwks(): array
    {
        $keys = Http::acceptJson()
            ->timeout(5)
            ->get($this->issuer().'/protocol/openid-connect/certs')
            ->throw()
            ->json('keys', []);

        return ['keys' => array_values(array_filter($keys, fn (array $key): bool => ($key['use'] ?? 'sig') === 'sig'))];
    }

    private function issuer(): string
    {
        return rtrim((string) config('services.keycloak.base_url'), '/').'/realms/'.config('services.keycloak.realms');
    }
}
