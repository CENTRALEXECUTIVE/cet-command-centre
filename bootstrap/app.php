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
        //
        // The LOGGED-IN driver's job-progress actions (driver/jobs/*/status, GPS,
        // ack-cash, etc.) are exempt for the SAME reason: a driver on a long job
        // leaves the phone idle for hours, the session's CSRF token goes stale, and
        // tapping "Complete" would 419 even though "remember me" keeps them signed
        // in. These actions only advance a job the driver already owns (the office
        // can correct any status), so CSRF adds little and breaks the one thing that
        // must always work from the roadside. Account-changing routes stay guarded.
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
            'consent/cookies',
            'job/*',
            'car/*',
            'tip/*',
            'driver/jobs/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
