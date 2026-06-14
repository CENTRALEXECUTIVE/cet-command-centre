<?php

namespace App\Providers;

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
        // HTTPS only in production (security requirement) — force generated
        // URLs and asset links to https behind the GoDaddy proxy.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
