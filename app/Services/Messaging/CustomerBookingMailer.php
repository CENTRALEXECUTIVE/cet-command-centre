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
        // Default ON (opted in by the directors): web-widget customers get an
        // automatic booking confirmation. Still fully guarded below — source must
        // be 'web', so ETO/import/calendar customers can never be emailed. The
        // office can switch it off in Settings → Web widgets.
        return (bool) Setting::get(self::SETTING, true);
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
        // Guard 4 — the shared safety gate (not terminal, pickup not in the past).
        if (! $booking->customerEmailAllowed()) {
            return false;
        }

        // A stable "tap to pay" link for an unpaid card booking, so the confirmation
        // lets the customer pay the amount due straight away — each leg of a return
        // is its own booking, so each gets its own confirmation with its own link
        // (outbound …A, return …B). Only when that booking's card provider is live
        // and there's a balance to take; cash/account/paid get no link.
        $payUrl = null;
        $due = (float) ($booking->transactionsAmountDue() ?? $booking->amountPayable() ?? 0);
        if (($booking->payment_method?->value ?? null) === 'card'
            && ! $booking->fareIsPaid()
            && $due > 0
            && app(\App\Services\Payments\PaymentGateway::class)->enabledForBooking($booking)) {
            $payUrl = \Illuminate\Support\Facades\URL::signedRoute('widget.pay', ['booking' => $booking->id]);
        }

        try {
            Mail::to($email)->send(new BookingConfirmationMail($booking, paid: false, payUrl: $payUrl));
        } catch (\Throwable $e) {
            \App\Support\MailFailure::report('customer booking confirmation', $e, $booking);

            return false;
        }

        $booking->forceFill(['meta' => array_merge($booking->meta ?? [], [
            'customer_email_sent' => now()->toIso8601String(),
        ])])->save();

        return true;
    }
}
