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

        // Inbound webhooks (shared-secret auth), the cookie-consent beacon, and
        // the public driver LINK are exempt from CSRF. The driver link is opened
        // from WhatsApp's in-app browser, which often drops cookies/sessions, so
        // a session-based CSRF token would make status/GPS posts fail ("page
        // expired"). The secret token in the /job/{token} URL is the auth here —
        // same model as webhooks — so CSRF adds nothing and only breaks it.
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
            'consent/cookies',
            'job/*',
            'tip/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
