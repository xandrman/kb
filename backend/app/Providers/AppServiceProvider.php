<?php

namespace App\Providers;

use App\Actions\AuthenticateWithKeycloakBearer;
use App\Http\Responses\KeycloakLogoutResponse;
use App\Models\User;
use App\Socialite\KeycloakProvider;
use Filament\Auth\Http\Responses\Contracts\LogoutResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LogoutResponse::class, KeycloakLogoutResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(SocialiteWasCalled::class, function (SocialiteWasCalled $event): void {
            $event->extendSocialite('keycloak', KeycloakProvider::class);
        });

        Auth::viaRequest('keycloak-bearer', fn (Request $request): ?User => $this->app
            ->make(AuthenticateWithKeycloakBearer::class)
            ->handle($request));

        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(60)->by(
            $request->user()?->getAuthIdentifier() ?: $request->ip()
        ));
    }
}
