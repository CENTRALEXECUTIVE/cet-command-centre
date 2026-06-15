<?php

namespace App\Services\Telephony;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Support\Str;

/**
 * Number masking: customer and driver connect through CET's single virtual
 * number, so neither sees the other's real number. Given an inbound caller, we
 * find their active job and return the counterpart's number to dial/text.
 */
class MaskingService
{
    /**
     * Resolve the number to connect an inbound caller to, based on the active
     * job linking the customer and the driver. Returns null if no match.
     */
    public function counterpartFor(string $fromNumber): ?string
    {
        $from = $this->normalise($fromNumber);

        $active = Booking::with(['customer', 'driver'])
            ->whereIn('status', [
                BookingStatus::Accepted->value,
                BookingStatus::EnRoute->value,
                BookingStatus::Collected->value,
            ])
            ->orderByDesc('pickup_at')
            ->get();

        foreach ($active as $booking) {
            $customer = $this->normalise($booking->customer?->phone);
            $driver = $this->normalise($booking->driver?->phone);

            if ($customer && $customer === $from && $driver) {
                return $booking->driver->phone; // customer → driver
            }
            if ($driver && $driver === $from && $customer) {
                return $booking->customer->phone; // driver → customer
            }
        }

        return null;
    }

    /** TwiML to bridge the call to the counterpart, or a polite failure. */
    public function dialTwiml(?string $counterpart): string
    {
        if ($counterpart) {
            $to = htmlspecialchars($counterpart, ENT_XML1);

            return '<?xml version="1.0" encoding="UTF-8"?>'
                .'<Response><Dial callerId="'.htmlspecialchars((string) config('services.twilio_masking.proxy_number'), ENT_XML1)."\">{$to}</Dial></Response>";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Response><Say>Sorry, we could not connect your call. Please contact Central Executive Transfers directly.</Say></Response>';
    }

    private function normalise(?string $number): ?string
    {
        if (blank($number)) {
            return null;
        }
        $number = preg_replace('/[^0-9+]/', '', $number);
        if (Str::startsWith($number, '0')) {
            $number = '+44'.substr($number, 1);
        }

        return $number;
    }
}
