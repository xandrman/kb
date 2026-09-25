<?php

namespace App\Http\Controllers\Auth;

use App\Actions\AuthenticateWithKeycloak;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class KeycloakController extends Controller
{
    /**
     * Send the visitor to Keycloak's authorization endpoint.
     */
    public function redirect(): SymfonyRedirectResponse
    {
        return Socialite::driver('keycloak')->redirect();
    }

    /**
     * Open a local session for the returning visitor.
     */
    public function callback(AuthenticateWithKeycloak $authenticate): RedirectResponse
    {
        try {
            $authenticate->handle();
        } catch (InvalidStateException) {
            return redirect()->route('keycloak.redirect');
        }

        return redirect()->intended(filament()->getUrl());
    }
}
