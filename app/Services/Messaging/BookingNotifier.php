<?php

namespace App\Services\Messaging;

use App\Models\Booking;
use App\Models\Message;
use Illuminate\Support\Str;

/**
 * Composes and dispatches the customer-facing WhatsApp messages tied to a
 * booking's lifecycle: confirmation on booking, reminders 24h and 2h before
 * pickup, and the live tracking link when the driver goes En Route.
 */
class BookingNotifier
{
    public function __construct(private readonly WhatsAppService $whatsApp) {}

    /** Sent immediately when a booking is created. */
    public function sendConfirmation(Booking $booking): ?Message
    {
        $to = $booking->customer?->phone;
        if (blank($to)) {
            return null;
        }

        $body = "Hi {$this->firstName($booking)}, your Central Executive Transfers booking is confirmed."
            ."\nRef: {$booking->reference}"
            ."\nPickup: {$booking->pickup_at->format('D d M, H:i')}"
            ."\nFrom: ".Str::limit($booking->pickup_address, 80)
            ."\nTo: ".Str::limit($booking->destination_address, 80)
            ."\nVehicle: {$booking->vehicleType?->name}."
            ."\nWe'll be in touch. Reply to this message if anything changes.";

        return $this->whatsApp->send($to, $body, [
            'type' => 'confirmation',
            'booking' => $booking,
        ]);
    }

    /**
     * Queues the ~24h and 2h reminders. The 24h reminder targets 24h before
     * pickup but is shifted into the 08:00–23:00 sending window so it never
     * lands overnight (matching how the office sends them by hand). The 2h nudge
     * is only queued if it too falls inside the window.
     */
    public function scheduleReminders(Booking $booking): void
    {
        $to = $booking->customer?->phone;
        if (blank($to)) {
            return;
        }

        // ~24h reminder — clamped into the daytime window.
        $at24h = $this->clampToSendWindow($booking->pickup_at->copy()->subDay());
        if ($at24h->isFuture() && $at24h->lt($booking->pickup_at)) {
            $this->queueReminder($booking, 'reminder_24h', $at24h, 'tomorrow');
        }

        // 2h nudge — only if it naturally falls within waking hours.
        $at2h = $booking->pickup_at->copy()->subHours(2);
        if ($at2h->isFuture() && $this->withinSendWindow($at2h)) {
            $this->queueReminder($booking, 'reminder_2h', $at2h, 'in 2 hours');
        }
    }

    private function queueReminder(Booking $booking, string $type, \Illuminate\Support\Carbon $when, string $window): void
    {
        $body = "Reminder: your CET car ({$booking->vehicleType?->name}) is booked for "
            ."{$booking->pickup_at->format('D d M, H:i')} ({$window})."
            ."\nPickup: ".Str::limit($booking->pickup_address, 80)
            ."\nRef: {$booking->reference}";

        $this->whatsApp->send((string) $booking->customer?->phone, $body, [
            'type' => $type,
            'booking' => $booking,
            'scheduled_for' => $when,
        ]);
    }

    /** The daytime sending window [start, end] on the given day. */
    private function sendWindow(\Illuminate\Support\Carbon $day): array
    {
        [$sh, $sm] = array_pad(explode(':', (string) config('cet.send_window.start', '08:00')), 2, 0);
        [$eh, $em] = array_pad(explode(':', (string) config('cet.send_window.end', '23:00')), 2, 0);

        return [
            $day->copy()->setTime((int) $sh, (int) $sm),
            $day->copy()->setTime((int) $eh, (int) $em),
        ];
    }

    private function withinSendWindow(\Illuminate\Support\Carbon $when): bool
    {
        [$start, $end] = $this->sendWindow($when);

        return $when->betweenIncluded($start, $end);
    }

    /**
     * Move a send time into the daytime window: before the start → the start of
     * that same day; after the end → the end of that same day.
     */
    private function clampToSendWindow(\Illuminate\Support\Carbon $when): \Illuminate\Support\Carbon
    {
        [$start, $end] = $this->sendWindow($when);

        if ($when->lt($start)) {
            return $start;
        }
        if ($when->gt($end)) {
            return $end;
        }

        return $when;
    }

    /** Queues the review request for ~30 minutes after job completion. */
    public function scheduleReviewRequest(Booking $booking): ?Message
    {
        $to = $booking->customer?->phone;
        if (blank($to)) {
            return null;
        }

        $delay = (int) config('cet.review_delay_minutes', 30);
        $body = "Thanks for travelling with Central Executive Transfers, {$this->firstName($booking)}!"
            ."\nWe'd love a quick review: ".config('cet.review_url')
            ."\nRef: {$booking->reference}";

        return $this->whatsApp->send($to, $body, [
            'type' => 'review_request',
            'booking' => $booking,
            'scheduled_for' => now()->addMinutes($delay),
        ]);
    }

    /**
     * "Here's your driver" — sent when a driver is allocated, giving the customer
     * the driver's name and the car (make, colour, registration) so they know
     * exactly who and what to look for. Skipped if no driver/number is set.
     */
    public function sendDriverDetails(Booking $booking): ?Message
    {
        $to = $booking->customer?->phone;
        $driver = $booking->driver;
        if (blank($to) || ! $driver) {
            return null;
        }

        $vehicle = $booking->vehicle ?? $driver->driverProfile?->defaultVehicle;
        $carParts = array_filter([
            $vehicle?->colour,
            trim(($vehicle?->make ?? '').' '.($vehicle?->model ?? '')) ?: null,
        ]);
        $car = $carParts ? implode(' ', $carParts) : $booking->vehicleType?->name;
        $reg = $vehicle?->registration ? " ({$vehicle->registration})" : '';

        $body = "Hi {$this->firstName($booking)}, your driver for booking {$booking->reference} is "
            ."{$driver->name}."
            .($car ? "\nCar: {$car}{$reg}." : '')
            ."\nPickup: {$booking->pickup_at->format('D d M, H:i')}."
            ."\nAny changes, just reply.";

        return $this->whatsApp->send($to, $body, [
            'type' => 'driver_details',
            'booking' => $booking,
        ]);
    }

    /** Sent when the driver marks Arrived at the pickup. */
    public function sendArrived(Booking $booking): ?Message
    {
        $to = $booking->customer?->phone;
        if (blank($to)) {
            return null;
        }

        $driver = $booking->driver?->name ? " ({$booking->driver->name})" : '';
        $body = "Your Central Executive Transfers driver{$driver} has arrived at the pickup point. "
            ."Ref: {$booking->reference}";

        return $this->whatsApp->send($to, $body, ['type' => 'custom', 'booking' => $booking]);
    }

    /** Sent when the driver goes En Route, including the live tracking link. */
    public function sendTrackingLink(Booking $booking, string $url): ?Message
    {
        $to = $booking->customer?->phone;
        if (blank($to)) {
            return null;
        }

        $body = "Your CET driver is on the way. Track your car live: {$url}"
            ."\nRef: {$booking->reference}";

        return $this->whatsApp->send($to, $body, [
            'type' => 'tracking_link',
            'booking' => $booking,
        ]);
    }

    private function firstName(Booking $booking): string
    {
        return Str::before($booking->customer?->name ?? 'there', ' ');
    }
}
