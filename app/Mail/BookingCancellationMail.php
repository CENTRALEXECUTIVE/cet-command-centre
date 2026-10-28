<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Cancellation confirmation to the CUSTOMER — sent by the office (previewed first),
 * never automatically. Confirms the booking is cancelled and, when a payment was
 * taken, that a refund will be processed. Routes from the PVT LTD address for a
 * non-VAT booking, like every other customer email.
 */
class BookingCancellationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Booking $booking)
    {
        if ($name = $this->booking->pvtMailer()) {
            $this->mailer($name);
        }
    }

    /**
     * The cancelled journeys this email covers (this one, plus any other cancelled
     * leg of the same trip not yet emailed) and the journeys still booked — so
     * cancelling a whole return sends ONE email, and cancelling just the return
     * says the outbound still stands.
     *
     * @return array{cancelled: \Illuminate\Support\Collection, still: \Illuminate\Support\Collection}
     */
    public function legs(): array
    {
        $all = $this->booking->invoiceGroupBookings();
        $all = $all->isNotEmpty() ? $all : collect([$this->booking]);
        $isOff = fn (Booking $b) => in_array($b->status, [\App\Enums\BookingStatus::Cancelled, \App\Enums\BookingStatus::NoShow], true);
        $ownSend = $this->booking->exists ? ($this->booking->fresh()?->meta['cancellation_email_sent'] ?? false) : false;

        return [
            // Not yet emailed — or emailed in the SAME send as this one (so a
            // preview after sending still shows exactly what went out).
            'cancelled' => $all->filter(fn (Booking $b) => $b->is($this->booking)
                || ($isOff($b) && (empty($b->meta['cancellation_email_sent'])
                    || ($b->meta['cancellation_email_sent'] ?? null) === $ownSend)))->values(),
            'still' => $all->reject($isOff)->values(),
        ];
    }

    /**
     * What we refund for the cancelled journeys ONLY: a refund the office recorded
     * wins; otherwise what was paid for each cancelled journey less any fee kept.
     * (It used to total the whole trip — cancelling just the return promised a
     * refund of the outbound too.)
     */
    public static function refundFor(Booking $b): float
    {
        if (($r = $b->cancellationRefund()) !== null) {
            return round($r, 2);
        }
        if ($b->payments->isNotEmpty()) {
            $paid = (float) $b->payments->where('status', 'paid')->sum('amount');
        } else {
            // This leg's own share — a whole-trip payment recorded on it is capped
            // at the leg's original (VAT-inclusive) price.
            $original = $b->cancellationOriginalFare();
            $cap = $original !== null
                ? ($b->vatInvoiceRequested() ? (app(\App\Services\Payments\VatService::class)->fromNet($original)['gross'] ?? $original) : $original)
                : ($b->amountPayable() ?? 0.0);
            $paid = $b->onlinePaidTotal() > 0 ? min($b->onlinePaidTotal(), $cap) : $b->transactionsPaidTotal();
        }

        return round(max(0.0, $paid - (float) ($b->cancellationFee() ?? 0)), 2);
    }

    public function envelope(): Envelope
    {
        $refs = $this->legs()['cancelled']->map(fn (Booking $b) => $b->external_reference ?: $b->reference)->implode(' / ');

        return new Envelope(
            from: new Address($this->booking->senderAddress(), $this->booking->senderName()),
            subject: 'Booking cancelled — '.$refs,
        );
    }

    public function content(): Content
    {
        $legs = $this->legs();
        $refund = round($legs['cancelled']->sum(fn (Booking $b) => self::refundFor($b)), 2);

        return new Content(view: 'emails.booking-cancellation', with: [
            'booking' => $this->booking,
            'cancelledLegs' => $legs['cancelled'],
            'stillBooked' => $legs['still'],
            'paid' => $refund,
            'company' => \App\Support\InvoiceProfile::companyFor($this->booking->vatInvoiceRequested()),
            'contactEmail' => $this->booking->senderAddress(),
        ]);
    }
}
