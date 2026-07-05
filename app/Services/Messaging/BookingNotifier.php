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

    /** Queues the 24h and 2h reminders (scheduled, not sent immediately). */
    public function scheduleReminders(Booking $booking): void
    {
        $to = $booking->customer?->phone;
        if (blank($to)) {
            return;
        }

        $reminders = [
            'reminder_24h' => $booking->pickup_at->copy()->subDay(),
            'reminder_2h' => $booking->pickup_at->copy()->subHours(2),
        ];

        foreach ($reminders as $type => $when) {
            if ($when->isPast()) {
                continue; // Booking is too close to schedule this reminder.
            }

            $window = $type === 'reminder_24h' ? 'tomorrow' : 'in 2 hours';
            $body = "Reminder: your CET car ({$booking->vehicleType?->name}) is booked for "
                ."{$booking->pickup_at->format('D d M, H:i')} ({$window})."
                ."\nPickup: ".Str::limit($booking->pickup_address, 80)
                ."\nRef: {$booking->reference}";

            $this->whatsApp->send($to, $body, [
                'type' => $type,
                'booking' => $booking,
                'scheduled_for' => $when,
            ]);
        }
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
