<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * One-command, idempotent restore of the Google Calendar to the operator's
 * exact export ("the base"). Runs the whole recovery in the right order so the
 * calendar ends up matching database/imports/base.ics byte-for-byte with NO
 * duplicates and NO over-deletion:
 *
 *   1. Maintenance mode ON  — stops the 5-minute cron fighting the restore.
 *   2. Full calendar wipe   — clears EVERY event so nothing is left behind.
 *   3. Wipe ETO bookings    — clean DB slate + reset rotation to the live order
 *                             (ABDI: MAN/LHR/HUY/BHX/STN/LGW/LPL/LTN/LBA + all
 *                             other unbooked airports; MAJ: EMA/Free Roam).
 *   4. Import the base ICS  — recreates every event exactly as the export.
 *   5. Verify               — confirms each event is live.
 *   6. Maintenance mode OFF — automation resumes; future emails update by
 *                             reference only, so old bookings are never touched.
 *
 * Usage:  php artisan cet:restore-base
 */
class RestoreBase extends Command
{
    protected $signature = 'cet:restore-base {path=database/imports/base.ics : the ICS export to restore from}';

    protected $description = 'Restore the calendar to the exact base export in one step (wipe + import + verify), no duplicates';

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! is_readable(base_path($path)) && ! is_readable($path)) {
            $this->error("Cannot read the base export at: {$path}");

            return self::FAILURE;
        }

        $this->line('');
        $this->info('CET — restoring the calendar to your base export. Sit back, this takes a few minutes.');
        $this->line('');

        // 1. Pause automation so the cron cannot interfere mid-restore.
        $this->step('1/5  Pausing automation');
        $this->call('down');

        try {
            // 2. Clear EVERY event on the calendar (true full wipe).
            $this->step('2/5  Clearing the calendar');
            $this->call('cet:purge-calendar', ['--all' => true]);

            // 3. Clean DB slate + reset rotation to the current driver order.
            $this->step('3/5  Resetting bookings & driver order');
            $this->call('cet:wipe-eto');

            // 4. Recreate every event exactly from the export (pushes to Google).
            //    This is the slow part — ~one Google call per event.
            $this->step('4/5  Restoring every booking from your export (this is the slow bit)');
            $this->call('cet:import-ics', ['path' => $path]);

            // 5. Confirm everything is live.
            $this->step('5/5  Verifying');
            $this->call('cet:verify-calendar');
        } finally {
            // Always bring the app back up, even if a step failed.
            $this->call('up');
        }

        $this->line('');
        $this->info('Done. The calendar now matches your export, and automation is back on.');
        $this->line('From here, new ETO emails are added automatically and update by reference — old bookings are never duplicated.');

        return self::SUCCESS;
    }

    private function step(string $message): void
    {
        $this->line('');
        $this->line("➤ {$message}…");
    }
}
