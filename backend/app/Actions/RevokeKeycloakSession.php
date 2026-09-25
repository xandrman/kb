<?php

namespace App\Actions;

use Illuminate\Support\Facades\Cache;

class RevokeKeycloakSession
{
    private const string CACHE_KEY_PREFIX = 'keycloak.revoked-session.';

    /**
     * After a logout the realm issues no new tokens for the session, so the mark only has to outlive
     * access tokens already issued: an hour is well above the realm access token lifespan.
     */
    private const int REVOKED_SECONDS = 3600;

    /**
     * Reject access tokens of a Keycloak session that has been logged out, although they are still unexpired.
     */
    public function handle(string $sessionId): void
    {
        Cache::put(self::CACHE_KEY_PREFIX.$sessionId, true, self::REVOKED_SECONDS);
    }

    public function isRevoked(string $sessionId): bool
    {
        return Cache::has(self::CACHE_KEY_PREFIX.$sessionId);
    }
}
