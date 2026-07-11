<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\CalendarEvent;
use App\Models\Setting;
use App\Services\Calendar\CalendarJobImporter;
use App\Services\Calendar\CalendarStats;
use App\Services\Calendar\CalendarTimeSync;
use App\Services\Calendar\GoogleCalendarService;
use App\Services\Messaging\BookingNotifier;
use Illuminate\Console\Command;

/**
 * Pull upcoming bookings into line with the LIVE Google Calendar — read-only.
 *
 * This runs on the scheduler (in the shell, where the Google connection is
 * reliable) so bookings stay matched to the calendar WITHOUT the website ever
 * needing to reach Google. The web pages then just read the database, which
 * never fails — removing the whole "the web can't reach Google" class of error.
 *
 * It reads the calendar ONCE for the window and matches every booking against
 * it by reference, mirroring the time and details. Never writes to Google.
 */
class CalendarRefresh extends Command
{
    protected $signature = 'cet:calendar-refresh {--days=60 : How many days ahead to refresh}';

    protected $description = 'Match upcoming bookings to the live Google Calendar and mirror the details (read-only)';

    public function handle(CalendarTimeSync $sync, GoogleCalendarService $google, CalendarStats $stats, CalendarJobImporter $importer, BookingNotifier $notifier): int
    {
        if (! $google->configured() || ! $google->active()) {
            $this->warn('Google Calendar is not connected/active — nothing to refresh.');

            return self::SUCCESS;
        }

        $days = max(1, (int) $this->option('days'));
        $from = now()->startOfDay();
        $to = now()->addDays($days)->endOfDay();

        $bookings = Booking::query()
            ->whereNotIn('status', [
                BookingStatus::Cancelled->value, BookingStatus::NoShow->value, BookingStatus::Complete->value,
            ])
            ->whereBetween('pickup_at', [$from, $to])
            ->with(['calendarEvent', 'customer'])
            ->orderBy('pickup_at')
            ->get();

        // One calendar read for the whole window; match each booking in memory.
        // NOTE: this runs even with zero existing bookings — brand-new events
        // added straight onto the calendar still need importing below.
        $calendarId = (string) Setting::get('calendar_id', 'admin@centralexecutivetransfers.co.uk');
        $events = $google->eventsBetween($calendarId, $from->copy()->subDays(3), $to->copy()->addDays(3));

        if ($events === []) {
            $this->warn('Read the calendar but it returned no events for the window — check the connection.');
        }

        $corrected = 0;
        $unmatched = [];
        foreach ($bookings as $booking) {
            $result = $sync->scan($booking, $events);
            if ($result['status'] !== 'ok') {
                $unmatched[] = ($booking->external_reference ?: $booking->reference)
                    .' ('.$booking->displayName().', '.$booking->pickup_at?->format('D d M H:i').')';

                continue;
            }
            if ($result['changes'] !== []) {
                $corrected++;
                // Reminders re-queue with the corrected time.
                if (isset($result['changes']['Pickup time'])) {
                    $booking->messages()->whereIn('type', ['reminder_24h', 'reminder_2h'])
                        ->where('status', 'queued')->delete();
                }
            }
        }

        $this->info("Refreshed {$bookings->count()} booking(s): {$corrected} corrected, ".count($unmatched).' not found on the calendar.');
        if ($unmatched !== []) {
            $this->line('Not found on the calendar (no event with that reference — e.g. phone bookings not added to Google):');
            foreach ($unmatched as $u) {
                $this->line('  · '.$u);
            }
        }

        // NEW bookings added straight onto the calendar (no booking in the
        // system yet) — import them so they appear in the Command Centre
        // automatically, linked to their existing event (never duplicated).
        $imported = $this->importCalendarOnlyJobs($events, $stats, $importer, $notifier);
        if ($imported > 0) {
            $this->info("Imported {$imported} new booking(s) that were added straight onto the calendar.");
        }

        return self::SUCCESS;
    }

    /**
     * Create bookings for calendar events that have no booking in the system —
     * so a job added directly on Google Calendar shows up here by itself.
     * Future events only; each is linked to its existing event id, and the
     * reminders are queued exactly like any other booking.
     *
     * @param  array<int, array<string, mixed>>  $events
     */
    private function importCalendarOnlyJobs(array $events, CalendarStats $stats, CalendarJobImporter $importer, BookingNotifier $notifier): int
    {
        // One query for everything already linked, not one per event.
        $linkedIds = CalendarEvent::whereNotNull('google_event_id')->pluck('google_event_id')->all();

        $imported = 0;
        foreach ($events as $event) {
            if (in_array($event['id'] ?? '', $linkedIds, true)) {
                continue; // already in the system
            }

            $parsed = $stats->rawEventToBookingData($event);
            if (! $parsed || ! $parsed['pickup_at'] || $parsed['pickup_at']->isPast()) {
                continue; // not a booking event, unreadable, or already gone
            }

            if ($importer->existingBookingFor($parsed)) {
                continue; // reference already known (e.g. ETO import without a link)
            }

            try {
                $booking = $importer->import($parsed);
                $notifier->ensureReminders($booking->load('customer'));
                \App\Models\WatchdogEvent::log('calendar_import',
                    'New booking from the calendar: '.$booking->pickup_at->format('D d M H:i').' '.$booking->displayName(),
                    'info', $booking);
                $imported++;
                $this->line('  + '.$booking->reference.' ← '.($parsed['reference'] ?: $parsed['customer_name'])
                    .' ('.$parsed['pickup_at']->format('D d M H:i').')');
            } catch (\Throwable $e) {
                $this->warn('  ! Could not import event '.($event['id'] ?? '?').': '.$e->getMessage());
            }
        }

        return $imported;
    }
}
