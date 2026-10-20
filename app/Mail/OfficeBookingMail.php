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
 * Internal "new web booking" notification to the office (mirrors the email ETO
 * sent the operator). Not a customer email — goes to the ops inbox only.
 */
class OfficeBookingMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  string  $stage  'new' (a booking has just been created) or
     *                         'paid' (its fare has just been paid online).
     */
    public function __construct(public Booking $booking, public string $stage = 'new') {}

    public function envelope(): Envelope
    {
        $ref = $this->booking->external_reference ?: $this->booking->reference;

        // A branded sender (and, when configured, a distinct from-address) so the
        // office copy reads as "Central Executive Transfers", not "Note to self".
        $fromAddress = trim((string) config('cet.office_from')) ?: config('mail.from.address');
        $replyTo = config('cet.ops_email');

        // An unpaid card booking is INCOMPLETE until the customer pays (ETO-style);
        // cash/account jobs are confirmed on creation.
        $awaiting = $this->stage !== 'paid'
            && ! $this->booking->fareIsPaid()
            && ($this->booking->payment_method?->value ?? null) === 'card';

        $subject = match (true) {
            $this->stage === 'paid' => 'Payment received — booking '.$ref,
            $awaiting => 'Incomplete booking (awaiting payment) — '.$ref,
            default => 'New booking '.$ref,
        };

        return new Envelope(
            from: new Address($fromAddress, 'Central Executive Transfers'),
            replyTo: $replyTo ? [new Address($replyTo, 'Central Executive Transfers')] : [],
            subject: $subject,
        );
    }

    public function content(): Content
    {
        // A "Pay now" link for an UNPAID card booking — the office can click it to
        // take payment over the phone, or copy the URL to send to the customer.
        // It's the same STABLE signed link the customer gets (widget.pay), so it
        // always charges the current price. Null for cash/account/paid.
        $payUrl = null;
        if (($this->booking->payment_method?->value ?? null) === 'card'
            && ! $this->booking->fareIsPaid()
            && (float) ($this->booking->amountPayable() ?? 0) > 0) {
            $payUrl = \Illuminate\Support\Facades\URL::signedRoute('widget.pay', ['booking' => $this->booking->id]);
        }

        return new Content(view: 'emails.office-booking', with: [
            'booking' => $this->booking,
            'vat' => $this->booking->fareVatBreakdown(),
            'stage' => $this->stage,
            'payUrl' => $payUrl,
        ]);
    }
}
