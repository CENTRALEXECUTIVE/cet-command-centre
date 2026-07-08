<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Setting;
use App\Services\Calendar\CalendarTimeSync;
use App\Services\Calendar\GoogleCalendarService;
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
    protected $signature = 'cet:calendar-refresh {--days=21 : How many days ahead to refresh}';

    protected $description = 'Match upcoming bookings to the live Google Calendar and mirror the details (read-only)';

    public function handle(CalendarTimeSync $sync, GoogleCalendarService $google): int
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

        if ($bookings->isEmpty()) {
            $this->info('No upcoming bookings to refresh.');

            return self::SUCCESS;
        }

        // One calendar read for the whole window; match each booking in memory.
        $calendarId = (string) Setting::get('calendar_id', 'admin@centralexecutivetransfers.co.uk');
        $events = $google->eventsBetween($calendarId, $from->copy()->subDays(3), $to->copy()->addDays(3));

        if ($events === []) {
            $this->warn('Read the calendar but it returned no events for the window — check the connection.');
        }

        $corrected = 0;
        $unmatched = 0;
        foreach ($bookings as $booking) {
            $result = $sync->scan($booking, $events);
            if ($result['status'] !== 'ok') {
                $unmatched++;

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

        $this->info("Refreshed {$bookings->count()} booking(s): {$corrected} corrected, {$unmatched} not found on the calendar.");

        return self::SUCCESS;
    }
}
