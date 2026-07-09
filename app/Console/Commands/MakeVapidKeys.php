<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Generates a one-time VAPID key pair for Web Push (driver notifications) and
 * prints the two .env lines to paste in. Run once per install. The private key
 * is a secret — it lives only in .env, never in the repo.
 */
class MakeVapidKeys extends Command
{
    protected $signature = 'cet:make-vapid';

    protected $description = 'Generate the VAPID keys for driver push notifications (paste the output into .env)';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->newLine();
        $this->info('Add these two lines to your .env, then run: php artisan config:clear');
        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->newLine();
        $this->warn('Keep the private key secret — it only ever goes in .env.');

        return self::SUCCESS;
    }
}
