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

        // Each lsphp worker can hold its OWN OPcache, so a single reset call only
        // clears the one worker that happens to answer it — leaving others still
        // serving stale bytecode (the "sometimes right, sometimes wrong" driver
        // link). Hit the endpoint many times so the reset lands on every worker
        // in the pool; `.user.ini` (revalidate on every request) then keeps them
        // fresh so future deploys don't need this at all.
        $ok = 0;
        $failed = 0;
        for ($i = 0; $i < 40; $i++) {
            try {
                $res = Http::timeout(10)->connectTimeout(5)->withoutVerifying()->get($url);
                if ($res->successful() && $res->json('opcache_reset') === true) {
                    $ok++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        if ($ok > 0) {
            $this->info("Web OPcache reset OK ({$ok} worker hits).");

            return self::SUCCESS;
        }
        $this->warn('OPcache reset could not be confirmed ('.$failed.' attempts failed).');

        // The CLI couldn't reach the web SAPI. Give a manual fallback: opening
        // this URL in a browser resets the web OPcache directly.
        $this->newLine();
        $this->line('  To clear it manually, open this once in your browser:');
        $this->line('  '.$url);
        $this->line('  (or use your host\'s "Restart PHP" control.)');

        return self::SUCCESS;
    }
}
