<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\Calendar\GoogleCalendarService;
use App\Services\CalendarEventBuilder;
use Illuminate\Console\Command;

/**
 * Safety net: re-confirms that every upcoming, non-cancelled booking is actually
 * on the calendar (event present and synced). Any that slipped through — no
 * event, or a pending/failed push — are rebuilt and pushed, reusing the existing
 * Google event id so nothing duplicates. Reports anything still missing.
 */
class VerifyCalendar extends Command
{
    protected $signature = 'cet:verify-calendar';

    protected $description = 'Re-confirm every upcoming booking is on the calendar; fix any that are missing';

    public function handle(CalendarEventBuilder $builder, GoogleCalendarService $calendar): int
    {
        $bookings = Booking::with(['calendarEvent', 'customer', 'vehicleType', 'airport', 'driver'])
            ->where('status', '!=', BookingStatus::Cancelled->value)
            ->where('pickup_at', '>=', now()->startOfDay())
            ->get();

        $onCalendar = 0;
        $fixed = 0;
        $missing = [];

        foreach ($bookings as $booking) {
            $event = $booking->calendarEvent;
            if ($event && $event->sync_status === 'synced') {
                $onCalendar++;

                continue;
            }

            // Not confirmed on the calendar → (re)build and push in place.
            $event = $builder->buildFor($booking);
            if ($calendar->push($event)) {
                $fixed++;
            } else {
                $missing[] = $booking->external_reference ?? $booking->reference;
            }
        }

        $this->info("Checked {$bookings->count()} upcoming booking(s): {$onCalendar} already on the calendar, "
            ."{$fixed} fixed/added, ".count($missing).' still missing.');
        if ($missing) {
            $this->warn('Still missing (will retry next run): '.implode(', ', $missing));
        }

        return self::SUCCESS;
    }
}
