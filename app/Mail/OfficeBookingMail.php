<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
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

    public function __construct(public Booking $booking) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New booking '.($this->booking->external_reference ?: $this->booking->reference),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.office-booking', with: [
            'booking' => $this->booking,
            'vat' => $this->booking->fareVatBreakdown(),
        ]);
    }
}
