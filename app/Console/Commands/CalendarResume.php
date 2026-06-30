<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;

/**
 * Resume calendar activity after a pause. Only takes effect if the hard env
 * override CALENDAR_SYNC_ENABLED is not set to false. After resuming, run
 * cet:sync-calendar to push any bookings that arrived while paused.
 */
class CalendarResume extends Command
{
    protected $signature = 'cet:calendar-resume';

    protected $description = 'Resume Google Calendar activity after a pause';

    public function handle(): int
    {
        Setting::set('calendar_paused', false, 'bool', 'calendar');

        if (config('services.google_calendar.sync_enabled') === false) {
            $this->warn('Setting cleared, but CALENDAR_SYNC_ENABLED=false in .env still forces the calendar OFF. Remove it to fully resume.');

            return self::SUCCESS;
        }

        $this->info('Calendar is RESUMED. Run cet:sync-calendar to push anything that arrived while paused.');

        return self::SUCCESS;
    }
}
