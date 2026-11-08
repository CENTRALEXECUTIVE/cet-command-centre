<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A card payment link for a specific booking transaction, emailed to the customer
 * by the office (admin-initiated — not an automatic send). Card payments are by
 * secure link only; no card data is handled by us.
 */
class PaymentLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Booking $booking,
        public float $amount,
        public string $payUrl,
    ) {
        // The PVT LTD company sends through its own mailer, kept separate.
        if ($name = $booking->pvtMailer()) {
            $this->mailer($name);
        }
    }

    public function envelope(): Envelope
    {
        $ref = $this->booking->external_reference ?: $this->booking->reference;

        return new Envelope(
            from: new \Illuminate\Mail\Mailables\Address($this->booking->senderAddress(), $this->booking->senderName()),
            subject: 'Central Executive Transfers — payment for booking '.$ref,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.payment-link', with: [
            'booking' => $this->booking,
            'amount' => $this->amount,
            'payUrl' => $this->payUrl,
        ] + $this->summary());
    }

    /**
     * What this payment is for: the trip's legs, what's still owed after this
     * payment, and whether the customer has paid anything before. A payment that
     * leaves money owing is a DEPOSIT (and the email says how the rest is paid);
     * one that clears it is "the balance" (something paid before) or the full fare.
     *
     * @return array{legs: \Illuminate\Support\Collection, remaining: float, paidBefore: bool, cashRest: bool}
     */
    public function summary(): array
    {
        $legs = $this->booking->tripBookings();
        if ($legs->isEmpty()) {
            $legs = collect([$this->booking]);
        }
        $legs = $legs->sortBy('pickup_at')->values();

        $due = round((float) $legs->sum(fn (Booking $b) => (float) ($b->transactionsAmountDue() ?? 0)), 2);
        $paidBefore = $legs->contains(fn (Booking $b) => $b->transactionsPaidTotal() > 0.009
            || $b->depositPaidAmount() > 0.009 || $b->onlinePaidTotal() > 0.009);

        return [
            'legs' => $legs,
            'remaining' => max(0.0, round($due - $this->amount, 2)),
            'paidBefore' => $paidBefore,
            'cashRest' => ($this->booking->payment_method?->value ?? null) === 'cash',
        ];
    }
}
