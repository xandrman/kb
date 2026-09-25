<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // There is no local login page: guests sign in through Keycloak, MCP hosts get 401 instead
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('mcp') ? null : route('keycloak.redirect'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // An MCP host must get 401 whatever it accepts: there is no login page to redirect to.
        // The Bearer challenge header is added by laravel/mcp itself
        $exceptions->render(fn (AuthenticationException $exception, Request $request) => $request->is('mcp')
            ? response()->json(['message' => $exception->getMessage()], 401)
            : null);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
