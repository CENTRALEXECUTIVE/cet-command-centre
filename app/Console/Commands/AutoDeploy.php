<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Self-deploy: pull the latest code from the deploy branch and apply it, driven
 * by Laravel's own scheduler (which already runs every minute via cron). This
 * removes the need for a separate cPanel cron for deploys — the one that runs
 * `php artisan schedule:run` is enough, so a pushed fix goes live on its own.
 *
 * It does NOT restart PHP (that could kill the very CLI process running the
 * scheduler). It doesn't need to: public/.user.ini makes every web worker
 * re-check files each request, and cet:opcache-clear force-resets the web
 * OPcache over HTTP — so the new code is served immediately without a restart.
 *
 * Best-effort and safe: up-to-date is a silent no-op, and a failure is logged
 * without disrupting the rest of the schedule. Disable with CET_AUTO_DEPLOY=false.
 */
class AutoDeploy extends Command
{
    protected $signature = 'cet:auto-deploy';

    protected $description = 'Pull and apply the latest code from the deploy branch (scheduler-driven).';

    public function handle(): int
    {
        if (app()->environment('testing')) {
            return self::SUCCESS;
        }
        if (! filter_var(env('CET_AUTO_DEPLOY', true), FILTER_VALIDATE_BOOLEAN)) {
            return self::SUCCESS; // explicitly disabled on this box
        }

        $branch = (string) env('CET_DEPLOY_BRANCH', 'claude/cet-command-centre-guide-r1azew');
        $root = base_path();

        // Cron/scheduler runs with a bare PATH — give git a sane one to find.
        $env = ['PATH' => '/usr/local/bin:/usr/bin:/bin:/usr/local/sbin:/usr/sbin'];
        $git = fn (array $cmd) => Process::path($root)->env($env)->timeout(300)->run($cmd);

        $fetch = $git(['git', 'fetch', '--quiet', 'origin', $branch]);
        if (! $fetch->successful()) {
            Log::warning('Auto-deploy: git fetch failed', ['err' => $fetch->errorOutput()]);

            return self::SUCCESS;
        }

        $local = trim($git(['git', 'rev-parse', 'HEAD'])->output());
        $remote = trim($git(['git', 'rev-parse', 'origin/'.$branch])->output());
        if ($local === '' || $remote === '' || $local === $remote) {
            return self::SUCCESS; // up to date (or unreadable) — nothing to do
        }

        $this->info("Auto-deploy: {$local} -> {$remote}");
        $reset = $git(['git', 'reset', '--hard', 'origin/'.$branch]);
        if (! $reset->successful()) {
            Log::warning('Auto-deploy: git reset failed', ['err' => $reset->errorOutput()]);

            return self::SUCCESS;
        }

        // Apply the deploy. optimize:clear recompiles views/config/routes; the
        // OPcache reset + public/.user.ini make the new bytecode serve at once.
        $this->callSilently('migrate', ['--force' => true]);
        $this->callSilently('optimize:clear');
        $this->callSilently('cet:opcache-clear');

        Log::info('Auto-deploy applied', ['from' => $local, 'to' => $remote]);

        return self::SUCCESS;
    }
}
