<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Confirmation email to a CUSTOMER who booked through the web widget.
 *
 * IMPORTANT: only ever used for widget (source='web') bookings, gated by the
 * `widget_customer_emails` setting. It is never sent to ETO/imported customers —
 * see CustomerBookingMailer, which is the only thing that dispatches this.
 */
class BookingConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Booking $booking) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your booking with Central Executive Transfers — '.$this->booking->reference,
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.booking-confirmation', with: [
            'booking' => $this->booking,
        ]);
    }
}
