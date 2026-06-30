<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\CalendarEvent;
use Database\Seeders\RotationSeeder;
use Illuminate\Console\Command;

/**
 * Clears the system's MEMORY of the old, pre-automation bookings — the ones
 * loaded by hand from an export (source = ics / import) — WITHOUT touching the
 * Google Calendar. The operator manages the historical calendar themselves
 * (their own ICS import); the system should not store or manage those jobs.
 *
 * Live bookings created by the email automation (source = outlook) are left
 * alone. Safe to run at any time. Rotation is reset to the current driver order.
 *
 * The calendar is never duplicated as a result: when a future email references
 * a job already on the calendar, GoogleCalendarService matches it by Booking
 * Reference and updates that event in place rather than adding a copy.
 *
 * Usage:  php artisan cet:forget-bookings
 */
class ForgetBookings extends Command
{
    /** Sources that came from a manual import, not the live email feed. */
    private const IMPORTED_SOURCES = ['ics', 'import'];

    protected $signature = 'cet:forget-bookings';

    protected $description = 'Forget pre-automation imported bookings from the database (calendar is NOT touched)';

    public function handle(): int
    {
        $query = Booking::where('source_system', 'eto')
            ->whereIn('source', self::IMPORTED_SOURCES);

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info('Nothing to forget — no imported pre-automation bookings in the database.');

            return self::SUCCESS;
        }

        // Remove the local calendar_event rows first (DB only — we never call
        // Google, so the events stay on the calendar), then the bookings.
        $bookingIds = (clone $query)->pluck('id');
        CalendarEvent::whereIn('booking_id', $bookingIds)->delete();
        $deleted = $query->forceDelete();

        // Reset the rotation pointer to the current live driver order.
        (new RotationSeeder)->run();

        $this->info("Forgot {$deleted} imported booking(s) from the database. The Google Calendar was NOT touched.");
        $this->line('Driver rotation reset to the current order. Live automation (source=outlook) bookings were left alone.');

        return self::SUCCESS;
    }
}
