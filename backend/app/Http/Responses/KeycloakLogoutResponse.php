<?php

namespace App\Http\Responses;

use Filament\Auth\Http\Responses\Contracts\LogoutResponse as LogoutResponseContract;
use Illuminate\Http\RedirectResponse;

class KeycloakLogoutResponse implements LogoutResponseContract
{
    /**
     * Follow the local logout with an RP-initiated logout at Keycloak,
     * otherwise the SSO session would silently sign the visitor back in.
     */
    public function toResponse($request): RedirectResponse
    {
        $query = http_build_query([
            'client_id' => config('services.keycloak.client_id'),
            'post_logout_redirect_uri' => url('/'),
        ]);

        $baseUrl = rtrim((string) config('services.keycloak.base_url'), '/');
        $realm = config('services.keycloak.realms');

        return redirect()->away("{$baseUrl}/realms/{$realm}/protocol/openid-connect/logout?{$query}");
    }
}
