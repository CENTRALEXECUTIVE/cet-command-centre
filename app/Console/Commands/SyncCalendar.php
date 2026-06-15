<?php

namespace App\Console\Commands;

use App\Models\CalendarEvent;
use App\Services\Calendar\GoogleCalendarService;
use Illuminate\Console\Command;

/**
 * Pushes pending/failed calendar events to Google Calendar. Scheduled every
 * few minutes. No-op (events stay pending) until credentials are configured.
 */
class SyncCalendar extends Command
{
    protected $signature = 'cet:sync-calendar';

    protected $description = 'Push pending booking events to Google Calendar';

    public function handle(GoogleCalendarService $calendar): int
    {
        if (! $calendar->configured()) {
            $this->warn('Google Calendar not configured — events left pending.');

            return self::SUCCESS;
        }

        $events = CalendarEvent::whereIn('sync_status', ['pending', 'failed'])->limit(100)->get();
        $synced = 0;
        foreach ($events as $event) {
            if ($calendar->push($event)) {
                $synced++;
            }
        }

        $this->info("Synced {$synced} of {$events->count()} calendar event(s).");

        return self::SUCCESS;
    }
}
