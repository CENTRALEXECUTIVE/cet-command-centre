<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\Flight\FlightMonitorService;
use Illuminate\Console\Command;

/**
 * Checks live flight status for upcoming airport-pickup bookings and adjusts
 * pickup times for delays. Scheduled every 15 minutes.
 */
class CheckFlights extends Command
{
    protected $signature = 'cet:check-flights';

    protected $description = 'Monitor flights for delays and auto-adjust pickups';

    public function handle(FlightMonitorService $monitor): int
    {
        // Only watch flights close to their pickup — that's when the status is
        // meaningful and changing, and it keeps API calls low enough for a FREE
        // flight-data tier. Widen the window (hours before pickup) as your quota
        // allows via CET config `flight_monitor_window_hours`.
        $windowHours = (int) config('cet.flight_monitor_window_hours', 6);

        $bookings = Booking::with(['customer', 'driver'])
            ->whereNotNull('flight_number')
            ->whereIn('status', [BookingStatus::Pending->value, BookingStatus::Allocated->value, BookingStatus::Accepted->value])
            ->whereBetween('pickup_at', [now()->subHour(), now()->addHours($windowHours)])
            ->get();

        $checked = 0;
        foreach ($bookings as $booking) {
            if ($monitor->monitor($booking)) {
                $checked++;
            }
        }

        $this->info("Checked {$checked} flight(s).");

        return self::SUCCESS;
    }
}
