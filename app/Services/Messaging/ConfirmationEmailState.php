<?php

namespace App\Services\Messaging;

use App\Models\Booking;
use Illuminate\Support\Facades\URL;

/**
 * What a customer confirmation sent from the booking page should look like RIGHT
 * NOW — one place, shared by the preview, "Resend confirmation" and "Email the
 * updated booking", so what the office previews is exactly what is sent:
 *
 *  - paid (nothing owed)        → the paid confirmation + invoice, no pay button;
 *  - card with a balance due    → on hold, with the stable pay link (widget.pay,
 *                                 charges the CURRENT amount due at click time);
 *  - cash / account             → a plain confirmation, no pay button;
 *  - no price yet               → the "request received" enquiry wording.
 *
 * A booking marked paid and then re-priced UP is shown as paid with the balance
 * listed as "Remaining" but NO pay button: the stable link treats a paid booking
 * as already settled, so a button there would dead-end the customer.
 */
class ConfirmationEmailState
{
    /** @return array{paid: bool, payUrl: ?string, due: float, label: string, warning: ?string} */
    public static function for(Booking $booking): array
    {
        $method = $booking->payment_method?->value ?? null;
        $dueRaw = $booking->transactionsAmountDue();
        $due = round((float) ($dueRaw ?? 0), 2);

        if ($booking->fareIsPaid()) {
            return [
                'paid' => true, 'payUrl' => null, 'due' => $due,
                'label' => 'Paid confirmation + invoice',
                'warning' => $due > 0.009
                    ? 'Marked paid but £'.number_format($due, 2).' is still owed (price changed after payment). The email lists it as remaining with no pay button — collect it by hand.'
                    : null,
            ];
        }

        if ($dueRaw !== null && $due <= 0.009 && $booking->transactionsPaidTotal() > 0) {
            return ['paid' => true, 'payUrl' => null, 'due' => 0.0, 'label' => 'Paid confirmation + invoice', 'warning' => null];
        }

        if ($method === 'cash') {
            return ['paid' => false, 'payUrl' => null, 'due' => $due, 'label' => 'Confirmation — pay the driver in cash', 'warning' => null];
        }
        if ($method === 'account') {
            return ['paid' => false, 'payUrl' => null, 'due' => $due, 'label' => 'Confirmation — billed to account', 'warning' => null];
        }

        if ($dueRaw === null) {
            return ['paid' => false, 'payUrl' => null, 'due' => 0.0, 'label' => 'Request received (no price yet)', 'warning' => 'No price on the job — the email reads as an enquiry. Set the price first.'];
        }

        return [
            'paid' => false,
            'payUrl' => URL::signedRoute('widget.pay', ['booking' => $booking->id]),
            'due' => $due,
            'label' => 'On hold — with a pay button for £'.number_format($due, 2),
            'warning' => null,
        ];
    }

    /** Imported (ETO / calendar migration) bookings need an explicit "send anyway". */
    public static function isImported(Booking $booking): bool
    {
        return in_array($booking->source_system, ['eto', 'calendar'], true);
    }

    /** Why a manual confirmation can't be sent at all, or null when it can. */
    public static function blockedReason(Booking $booking): ?string
    {
        if (blank($booking->customer?->email)) {
            return 'No customer email on file — add one via Edit booking.';
        }
        if (in_array($booking->status, [\App\Enums\BookingStatus::Cancelled, \App\Enums\BookingStatus::NoShow], true)) {
            return 'This booking is '.str_replace('_', '-', $booking->status->value).' — use the cancellation email instead.';
        }

        return null;
    }
}
