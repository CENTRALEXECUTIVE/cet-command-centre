<?php

namespace App\Console\Commands;

use App\Services\Social\MetaInsightsService;
use Illuminate\Console\Command;

/** Nightly: pull Facebook/Instagram followers and post numbers (read-only). */
class SyncSocialStats extends Command
{
    protected $signature = 'cet:social-sync';

    protected $description = 'Pull Facebook and Instagram follower counts and post numbers into the Social Media Command Centre (read-only).';

    public function handle(MetaInsightsService $meta): int
    {
        if (! $meta->configured()) {
            $this->info('Meta not connected; nothing to do.');

            return self::SUCCESS;
        }
        $r = $meta->sync();
        $this->info($r['ok']
            ? "Synced: {$r['facebook']} Facebook, {$r['instagram']} Instagram posts ({$r['created']} new)."
            : 'Sync failed: '.($r['error'] ?? 'unknown'));

        return self::SUCCESS;
    }
}
