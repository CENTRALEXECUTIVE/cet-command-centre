<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;

/**
 * Hard-pause ALL calendar activity. While paused, the system never reads or
 * writes the Google Calendar — pushes, updates, deletes and purges all no-op.
 * Bookings still flow into the database; they just sit pending until resumed.
 */
class CalendarPause extends Command
{
    protected $signature = 'cet:calendar-pause';

    protected $description = 'Pause all Google Calendar activity (nothing is read or written until resumed)';

    public function handle(): int
    {
        Setting::set('calendar_paused', true, 'bool', 'calendar');
        $this->info('Calendar is PAUSED. The system will not touch the calendar until you run cet:calendar-resume.');

        return self::SUCCESS;
    }
}
