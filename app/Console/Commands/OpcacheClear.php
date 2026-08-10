<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Reset the PHP OPcache for the WEB process after a deploy.
 *
 * `php artisan optimize:clear` clears Laravel's own caches (config, routes,
 * views) but NOT the PHP OPcache — the compiled bytecode of the app's .php
 * files. Under php-fpm / LiteSpeed the web process can keep serving the OLD
 * compiled code for a while after a git deploy, so some requests run new code
 * and some run old — which showed up as the driver's money line flipping
 * between "Paid" and the correct cash amount right after a deploy.
 *
 * OPcache lives in the WEB process, not the CLI, so this command resets it by
 * calling a token-guarded web route over HTTP (APP_URL). It never fails the
 * deploy — a warning is enough if the self-call can't be made.
 */
class OpcacheClear extends Command
{
    protected $signature = 'cet:opcache-clear';

    protected $description = 'Reset the web PHP OPcache so a fresh deploy is served immediately';

    public function handle(): int
    {
        // Also reset the CLI OPcache (harmless; shares SHM with the web pool on
        // some LiteSpeed setups).
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        $key = (string) config('app.key');
        if ($key === '') {
            $this->warn('APP_KEY is not set — cannot build the OPcache-reset token.');

            return self::SUCCESS;
        }

        $token = hash_hmac('sha256', 'opcache-reset', $key);
        $url = rtrim((string) config('app.url'), '/').'/__cet/opcache/'.$token;

        try {
            $res = Http::timeout(10)->withoutVerifying()->get($url);
            if ($res->successful() && $res->json('opcache_reset') === true) {
                $this->info('Web OPcache reset OK.');
            } else {
                $this->warn('OPcache reset call to '.$url.' returned HTTP '.$res->status()
                    .'. Check APP_URL, or use your host\'s "Restart PHP" control.');
            }
        } catch (\Throwable $e) {
            $this->warn('Could not reach '.$url.' to reset the web OPcache ('.$e->getMessage()
                .'). Use your host\'s "Restart PHP" control instead.');
        }

        return self::SUCCESS;
    }
}
