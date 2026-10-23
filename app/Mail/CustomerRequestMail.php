<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Internal "customer wants to change/cancel a booking" notification to the office.
 * Time-sensitive, so it emails the ops inbox (not just push), mirroring
 * OfficeBookingMail. The office still actions it by hand — nothing auto-changes.
 */
class CustomerRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param  'cancel'|'change'  $requestType */
    public function __construct(
        public Booking $booking,
        public string $requestType,
        public ?string $message = null,
    ) {}

    public function envelope(): Envelope
    {
        $what = $this->requestType === 'cancel' ? 'cancellation' : 'change';

        return new Envelope(
            subject: '⚠️ Customer '.$what.' request — '.($this->booking->external_reference ?: $this->booking->reference),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.customer-request', with: [
            'booking' => $this->booking,
            'requestType' => $this->requestType,
            'message' => $this->message,
        ]);
    }
}
