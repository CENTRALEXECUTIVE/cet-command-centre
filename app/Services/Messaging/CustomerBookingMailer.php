<?php

namespace App\Services\Messaging;

use App\Mail\BookingConfirmationMail;
use App\Models\Booking;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The ONLY thing that emails a customer automatically — and it is deliberately
 * hemmed in so it can never reach ETO / existing-website customers:
 *
 *   1. source MUST be 'web' (i.e. made through our own booking widget). Every
 *      ETO/import/calendar/outlook booking has a different source and is skipped.
 *   2. the `widget_customer_emails` setting MUST be on (OFF by default).
 *   3. the customer must have an email, and it sends at most once per booking.
 *
 * So a CSV import, calendar sync, or any bulk operation can never trigger it.
 */
class CustomerBookingMailer
{
    /** The setting key that gates ALL automatic customer emails. */
    public const SETTING = 'widget_customer_emails';

    public static function enabled(): bool
    {
        return (bool) Setting::get(self::SETTING, false);
    }

    /**
     * Send a confirmation to a WEB-WIDGET booking's customer, if allowed.
     * Returns true only when an email was actually sent.
     */
    public function confirmIfWebBooking(Booking $booking): bool
    {
        // Guard 1 — web widget only. This is the line that protects every
        // existing/ETO customer: their bookings are never source 'web'.
        if ($booking->source !== 'web') {
            return false;
        }
        // Guard 2 — master switch, off by default.
        if (! self::enabled()) {
            return false;
        }

        $email = $booking->customer?->email;
        if (blank($email)) {
            return false;
        }
        // Guard 3 — once per booking.
        if (! empty($booking->meta['customer_email_sent'])) {
            return false;
        }

        try {
            Mail::to($email)->send(new BookingConfirmationMail($booking));
        } catch (\Throwable $e) {
            Log::warning('[widget] customer confirmation email failed: '.$e->getMessage());

            return false;
        }

        $booking->forceFill(['meta' => array_merge($booking->meta ?? [], [
            'customer_email_sent' => now()->toIso8601String(),
        ])])->save();

        return true;
    }
}
