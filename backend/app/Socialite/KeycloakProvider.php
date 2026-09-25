<?php

namespace App\Socialite;

use SocialiteProviders\Keycloak\Provider;

class KeycloakProvider extends Provider
{
    /**
     * Профиль и email нужны для локальной записи; без них userinfo отдаёт только sub.
     *
     * @var array<int, string>
     */
    protected $scopes = ['openid', 'profile', 'email'];

    /**
     * Клиент kb-app объявлен с pkce.code.challenge.method = S256.
     *
     * @var bool
     */
    protected $usesPKCE = true;
}
