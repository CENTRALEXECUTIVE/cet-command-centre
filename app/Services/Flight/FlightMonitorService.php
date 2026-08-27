<?php

namespace App\Services\Flight;

use App\Models\Booking;
use App\Models\FlightMonitor;
use App\Models\JobNudge;
use App\Services\Messaging\WhatsAppService;
use App\Services\Push\WebPushService;
use App\Services\Watchdog\AdminAlerts;
use Illuminate\Support\Str;

/**
 * Flight monitoring: checks the live arrival status for airport-pickup bookings
 * and keeps the OFFICE and the DRIVER up to date — for free, via internal push —
 * on the three things that change a pickup:
 *
 *   • DELAYED   — the flight is running late (pickup pushed back automatically).
 *   • EARLY     — the flight will land sooner than scheduled (driver must go
 *                 earlier; we alert but never silently move the pickup earlier).
 *   • CANCELLED — the flight is off; the office decides what to do.
 *   • LANDED    — the passenger is on the ground (a heads-up to the driver).
 *
 * Office alerts go through AdminAlerts (dashboard feed + admin push, honouring
 * each admin's preferences); driver alerts go through Web Push. Each event fires
 * at most once per booking (deduped in job_nudges), so nothing spams. Customer
 * messages stay MANUAL — we never auto-message the customer; the office taps the
 * wa.me link if they want to tell them.
 */
class FlightMonitorService
{
    public function __construct(
        private readonly FlightStatusClient $flights,
        private readonly WhatsAppService $whatsApp,
        private readonly AdminAlerts $adminAlerts,
        private readonly WebPushService $push,
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

        // How far the estimated arrival is from schedule: + late, − early.
        $deltaMin = ($info['scheduled_arrival'] && $info['estimated_arrival'])
            ? (int) round(($info['estimated_arrival']->timestamp - $info['scheduled_arrival']->timestamp) / 60)
            : (int) $info['delay_minutes'];

        $status = strtolower((string) $info['status']);
        $flight = $booking->flight_number;

        // 1) CANCELLED — critical; the office decides what happens next.
        if ($status === 'cancelled') {
            $this->alert($booking, 'cancelled', 'critical',
                "✈️ {$flight} CANCELLED",
                "Flight {$flight} for {$booking->displayName()} is showing CANCELLED. Check with the passenger before dispatching.");
        }

        // 2) DELAYED — push the pickup back automatically, and tell office + driver.
        if ($info['delay_minutes'] >= $threshold || $deltaMin >= $threshold) {
            $mins = max($info['delay_minutes'], $deltaMin);
            if (! $monitor->pickup_adjusted) {
                $this->adjustAndNotifyCustomer($booking, $mins);
                $monitor->pickup_adjusted = true;
                $monitor->customer_notified = true;
            }
            $this->alert($booking, 'delayed', 'warning',
                "✈️ {$flight} delayed ~{$mins} min",
                "Flight {$flight} for {$booking->displayName()} is delayed ~{$mins} min. Pickup moved to {$booking->fresh()->pickup_at->format('H:i')}.");
        }

        // 3) EARLY — landing sooner than scheduled; driver needs to be there
        //    sooner. Alert only (we never silently move a pickup earlier).
        if ($deltaMin <= -$threshold && $status !== 'cancelled') {
            $earlier = abs($deltaMin);
            $eta = $info['estimated_arrival']?->format('H:i');
            $this->alert($booking, 'early', 'warning',
                "✈️ {$flight} landing ~{$earlier} min EARLY",
                "Flight {$flight} for {$booking->displayName()} is now landing ~{$earlier} min early".($eta ? " (~{$eta})" : '').". Be ready to set off sooner.");
        }

        // 4) LANDED — a quiet heads-up so the driver knows the passenger's down.
        if (in_array($status, ['landed', 'arrived'], true)) {
            $this->alert($booking, 'landed', 'info',
                "✈️ {$flight} has landed",
                "Flight {$flight} for {$booking->displayName()} has landed. Head to the meeting point.", officeToo: false);
        }

        $monitor->save();

        return $monitor;
    }

    /**
     * Fire an event to the office (once) and the driver (once). Deduped per
     * (booking, event) so a 15-minute re-check never repeats an alert.
     */
    private function alert(Booking $booking, string $event, string $severity, string $title, string $body, bool $officeToo = true): void
    {
        if ($officeToo) {
            $this->adminAlerts->send(
                $booking,
                nudgeType: 'flight_'.$event,
                prefType: 'flight_update',
                title: $title,
                body: $body,
                severity: $severity,
                maxSends: 1,
                repeatMinutes: 24 * 60,
            );
        }

        $this->pushDriverOnce($booking, $event, $title, $body);
    }

    /** Push the driver at most once for this flight event. */
    private function pushDriverOnce(Booking $booking, string $event, string $title, string $body): void
    {
        $driver = $booking->driver;
        if (! $driver) {
            return;
        }
        $type = 'flight_'.$event;
        $already = JobNudge::where('booking_id', $booking->id)
            ->where('nudge_type', $type)
            ->where('recipient_type', 'driver')
            ->exists();
        if ($already) {
            return;
        }

        $this->push->sendToUser($driver, $title, $body, ['url' => route('driver.job', $booking)]);

        JobNudge::create([
            'booking_id' => $booking->id,
            'nudge_type' => $type,
            'recipient_type' => 'driver',
            'sent_at' => now(),
            'channel' => 'push',
            'created_at' => now(),
        ]);
    }

    /**
     * Push the pickup back for a delay and queue a customer heads-up. The message
     * is written to the messages ledger for the office to send by hand (wa.me) —
     * it is never auto-sent, per the "nothing auto-sends to customers" rule.
     */
    private function adjustAndNotifyCustomer(Booking $booking, int $delayMinutes): void
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
