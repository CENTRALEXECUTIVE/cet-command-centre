<?php

namespace App\Services\Flight;

use App\Models\Booking;
use App\Models\FlightMonitor;
use App\Services\Messaging\WhatsAppService;
use Illuminate\Support\Str;

/**
 * Flight delay monitoring: checks the live arrival status for airport-pickup
 * bookings and, when a flight is delayed beyond the threshold, automatically
 * pushes the pickup time back and notifies the customer once.
 */
class FlightMonitorService
{
    public function __construct(
        private readonly FlightStatusClient $flights,
        private readonly WhatsAppService $whatsApp,
    ) {}

    public function monitor(Booking $booking): ?FlightMonitor
    {
        if (blank($booking->flight_number)) {
            return null;
        }

        $info = $this->flights->arrival($booking->flight_number, $booking->pickup_at);
        if (! $info) {
            return null;
        }

        $monitor = FlightMonitor::firstOrNew(['booking_id' => $booking->id]);
        $monitor->pickup_adjusted ??= false;
        $monitor->customer_notified ??= false;
        $monitor->fill([
            'flight_number' => $booking->flight_number,
            'flight_date' => $booking->pickup_at->toDateString(),
            'scheduled_arrival' => $info['scheduled_arrival'],
            'estimated_arrival' => $info['estimated_arrival'],
            'status' => $info['status'],
            'delay_minutes' => $info['delay_minutes'],
            'last_checked_at' => now(),
            'raw_response' => $info,
        ]);

        $threshold = (int) config('cet.flight_delay_threshold', 15);
        if ($info['delay_minutes'] >= $threshold && ! $monitor->pickup_adjusted) {
            $this->adjustAndNotify($booking, $info['delay_minutes']);
            $monitor->pickup_adjusted = true;
            $monitor->customer_notified = true;
        }

        $monitor->save();

        return $monitor;
    }

    private function adjustAndNotify(Booking $booking, int $delayMinutes): void
    {
        $newPickup = $booking->pickup_at->copy()->addMinutes($delayMinutes);
        $booking->forceFill(['pickup_at' => $newPickup])->save();

        $phone = $booking->customer?->phone;
        if (blank($phone)) {
            return;
        }

        $name = Str::before($booking->customer->name ?? 'there', ' ');
        $body = "Hi {$name}, your flight {$booking->flight_number} is delayed ~{$delayMinutes} min. "
            ."We've automatically moved your Central Executive Transfers pickup to "
            ."{$newPickup->format('H:i')}. No action needed — your driver will be there.";

        $this->whatsApp->send($phone, $body, ['type' => 'custom', 'booking' => $booking]);
    }
}
