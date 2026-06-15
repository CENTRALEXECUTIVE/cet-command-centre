<?php

use App\Http\Middleware\EnsureUserHasRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Role-based access control alias (principle of least privilege).
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
        ]);

        // Inbound webhooks (shared-secret auth) and the public cookie-consent
        // beacon are exempt from CSRF.
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
            'consent/cookies',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
