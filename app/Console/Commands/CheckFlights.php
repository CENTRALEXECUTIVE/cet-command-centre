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
        $bookings = Booking::with('customer')
            ->whereNotNull('flight_number')
            ->whereIn('status', [BookingStatus::Pending->value, BookingStatus::Allocated->value, BookingStatus::Accepted->value])
            ->whereBetween('pickup_at', [now()->subHour(), now()->addDay()])
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
