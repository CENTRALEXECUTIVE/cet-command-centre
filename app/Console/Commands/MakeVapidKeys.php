<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Generates a one-time VAPID key pair for Web Push (driver + admin
 * notifications) and writes it straight into .env, so nobody has to hand-copy a
 * secret. Run once per install. Use --show to only print the lines instead of
 * writing them, and --force to replace keys that already exist.
 *
 * The private key is a secret — it lives only in .env, never in the repo.
 */
class MakeVapidKeys extends Command
{
    protected $signature = 'cet:make-vapid {--show : Only print the .env lines, do not write them} {--force : Overwrite VAPID keys that already exist}';

    protected $description = 'Generate the VAPID keys for push notifications and write them to .env';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();
        $public = $keys['publicKey'];
        $private = $keys['privateKey'];

        // --show: keep the old behaviour of just printing the two lines.
        if ($this->option('show')) {
            $this->printLines($public, $private);

            return self::SUCCESS;
        }

        $envPath = app()->environmentFilePath();

        if (! file_exists($envPath) || ! is_writable($envPath)) {
            $this->warn('.env is not writable here — printing the lines to paste instead.');
            $this->printLines($public, $private);

            return self::SUCCESS;
        }

        $env = file_get_contents($envPath);

        // Don't silently clobber working keys — existing driver subscriptions
        // only keep working with the SAME pair.
        if (! $this->option('force') && preg_match('/^VAPID_PRIVATE_KEY=\S/m', $env)) {
            $this->warn('VAPID keys already exist in .env. Re-run with --force to replace them.');
            $this->line('(Replacing them invalidates every current push subscription — drivers would need to re-enable.)');

            return self::SUCCESS;
        }

        $env = $this->upsert($env, 'VAPID_PUBLIC_KEY', $public);
        $env = $this->upsert($env, 'VAPID_PRIVATE_KEY', $private);
        $env = $this->upsert($env, 'VAPID_SUBJECT', 'mailto:admin@centralexecutivetransfers.co.uk');

        file_put_contents($envPath, $env);
        $this->call('config:clear');

        $this->newLine();
        $this->info('✓ VAPID keys written to .env and config cleared. Push notifications are now live.');
        $this->line('  Enable them on your phone: Settings → Notifications → Enable, then Send a test notification.');

        return self::SUCCESS;
    }

    /** Replace KEY=... in place, or append it if the key isn't present yet. */
    private function upsert(string $env, string $key, string $value): string
    {
        $line = $key.'='.$value;

        if (preg_match('/^'.preg_quote($key, '/').'=.*$/m', $env)) {
            // Callback replacement so characters in the key (e.g. $) are literal.
            return preg_replace_callback('/^'.preg_quote($key, '/').'=.*$/m', fn () => $line, $env);
        }

        return rtrim($env, "\n")."\n".$line."\n";
    }

    private function printLines(string $public, string $private): void
    {
        $this->newLine();
        $this->info('Add these two lines to your .env, then run: php artisan config:clear');
        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY='.$public);
        $this->line('VAPID_PRIVATE_KEY='.$private);
        $this->newLine();
        $this->warn('Keep the private key secret — it only ever goes in .env.');
    }
}
