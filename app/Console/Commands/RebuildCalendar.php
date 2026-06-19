<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\CalendarEventBuilder;
use App\Services\RotationService;
use Illuminate\Console\Command;

/**
 * Rebuilds the calendar event (title, emoji, description, driver tag) for every
 * active booking from the current rules, in booking order. Reuses each existing
 * Google event id, so cet:sync-calendar then UPDATES the same entries in place —
 * never creating duplicates. Use after a formatting/rule change.
 */
class RebuildCalendar extends Command
{
    protected $signature = 'cet:rebuild-calendar';

    protected $description = 'Rebuild all calendar events from the current rules (no duplicates)';

    public function handle(CalendarEventBuilder $builder, RotationService $rotation): int
    {
        $bookings = Booking::with(['customer', 'vehicleType', 'airport', 'driver'])
            ->where('status', '!=', BookingStatus::Cancelled->value)
            ->orderBy('pickup_at')
            ->get();

        foreach ($bookings as $booking) {
            // Backfill the rotation driver for Executive saloon jobs that never
            // got one (e.g. imported before rotation was wired in).
            if ($booking->vehicleType?->affects_rotation && ! $booking->driver_id) {
                $rotation->allocate($booking);
                $booking->refresh()->load('driver');
            }
            $builder->buildFor($booking);
        }

        $this->info("Rebuilt {$bookings->count()} calendar event(s). Run cet:sync-calendar to push the updates.");

        return self::SUCCESS;
    }
}
