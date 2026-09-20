<?php

use App\Http\Middleware\EnsureFacilityIsActive;
use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\EnsureUserHasRole;
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
    ->withMiddleware(function (Middleware $middleware) {
        // A patient's rating, and their "I've arrived", are sent from their tracking page, whose live part is
        // fetched without cookies, so it can't carry a session's CSRF token. The link's secret is the credential,
        // and there is no login or session for a forged request to ride on.
        $middleware->validateCsrfTokens(except: ['t/*/feedback', 't/*/arrived']);

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'facility.active' => EnsureFacilityIsActive::class,
            'password.changed' => EnsurePasswordIsChanged::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
