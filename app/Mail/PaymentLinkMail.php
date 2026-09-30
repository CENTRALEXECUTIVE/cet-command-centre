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
    ) {}

    public function envelope(): Envelope
    {
        $ref = $this->booking->external_reference ?: $this->booking->reference;

        return new Envelope(subject: 'Central Executive Transfers — payment for booking '.$ref);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.payment-link', with: [
            'booking' => $this->booking,
            'amount' => $this->amount,
            'payUrl' => $this->payUrl,
        ]);
    }
}
