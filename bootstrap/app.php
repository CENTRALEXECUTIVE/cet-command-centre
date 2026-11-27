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
            // Per-route rate limits (a shared counter refused real web bookings).
            'throttle' => \App\Http\Middleware\ThrottlePerRoute::class,
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
            'widget/book/beacon', // log-only, sent by the browser as the page closes
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // "Page expired" on a website booking press (the security token ran out while the
        // page sat open): never lose it (27 Nov 2026). Log every detail, alert the directors
        // and email the office, then send the customer back with their details kept.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, \Illuminate\Http\Request $request) {
            if ($e->getStatusCode() !== 419 || ! ($e->getPrevious() instanceof \Illuminate\Session\TokenMismatchException)) {
                return null;
            }
            $path = trim($request->path(), '/');
            if (! $request->isMethod('post') || ! in_array($path, ['widget/book', 'book'], true)) {
                return null;
            }
            $form = $path === 'widget/book' ? 'widget' : 'book';
            \App\Models\BookingAttempt::record($form, 'error', $request, ['details' => ['message' => 'Page expired (security token ran out) — customer asked to press Book again']]);
            \App\Support\UnsavedBookingBackup::send($request, 'the page had expired');

            return redirect()->back()->withInput($request->except(['password', '_token', 'submission_token']))
                ->withErrors(['pickup_at' => 'Your booking page had been open a while, so please press Book again — your details are kept below.']);
        });
    })->create();
