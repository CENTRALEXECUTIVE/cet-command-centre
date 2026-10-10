<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Force generated URLs and asset links to https on any real server
        // (production AND staging) — links sent to customers/drivers must never
        // come out as http, which triggers "not secure" errors on their phones.
        // Local dev and the test suite stay on http.
        if (! $this->app->environment('local', 'testing')) {
            URL::forceScheme('https');
        }

        // Use our own lightweight pagination markup (the default Tailwind view
        // renders oversized SVG arrows without Tailwind's utility classes).
        Paginator::defaultView('vendor.pagination.cet');
        Paginator::defaultSimpleView('vendor.pagination.cet');

        // Merge office-editable booking settings over config('cet.*'). Wrapped so a
        // not-yet-migrated DB (e.g. during `migrate`) can never take the app down.
        try {
            \App\Support\BookingSettings::apply();
        } catch (\Throwable $e) {
            // Settings table not ready / unreachable — fall back to config defaults.
        }

        // In-app SMTP (Settings → Email) merged over config('mail.*'), so email can
        // be switched on without touching the server .env. Guarded like above.
        try {
            \App\Support\MailSettings::apply();
        } catch (\Throwable $e) {
            // Settings table not ready — keep the .env/config mailer.
        }
    }
}
