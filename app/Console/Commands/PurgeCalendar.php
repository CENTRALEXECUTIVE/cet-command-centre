<?php

namespace App\Console\Commands;

use App\Models\CalendarEvent;
use App\Models\Setting;
use App\Services\Calendar\GoogleCalendarService;
use Illuminate\Console\Command;

/**
 * Deletes every event this system ever created on the Google Calendar — even
 * orphaned duplicates left by earlier runs that we no longer track — then clears
 * the local event ids so the next sync re-creates each booking exactly once.
 * Personal (human-created) events on the calendar are left alone.
 */
class PurgeCalendar extends Command
{
    protected $signature = 'cet:purge-calendar {--all : delete EVERY event on the calendar (full wipe for an exact re-import), not just system-created ones}';

    protected $description = 'Delete all system-created Google Calendar events (removes orphan duplicates), then reset for a clean rebuild';

    public function handle(GoogleCalendarService $calendar): int
    {
        if (! $calendar->configured()) {
            $this->error('Google Calendar not configured.');

            return self::FAILURE;
        }

        $calendarId = Setting::get('calendar_id', 'admin@centralexecutivetransfers.co.uk');
        $deleted = $this->option('all')
            ? $calendar->purgeAllEvents($calendarId)
            : $calendar->purgeOwnEvents($calendarId);

        // Forget the old Google ids so the rebuild POSTs fresh single copies.
        CalendarEvent::query()->update(['google_event_id' => null, 'sync_status' => 'pending']);

        $this->info("Deleted {$deleted} system-created event(s) from the calendar and reset local links.");
        $this->line('Now run: php artisan cet:sync-calendar  (re-creates each booking once)');

        return self::SUCCESS;
    }
}
