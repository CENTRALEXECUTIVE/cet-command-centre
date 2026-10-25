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

    public function envelope(): Envelope
    {
        $ref = $this->booking->external_reference ?: $this->booking->reference;

        return new Envelope(
            from: new Address($this->booking->senderAddress(), $this->booking->senderName()),
            subject: 'Booking cancelled — '.$ref,
        );
    }

    public function content(): Content
    {
        // How much (if anything) has actually been paid — drives whether we mention
        // a refund. Group-aware so a return pair reflects the whole trip collected.
        $paid = 0.0;
        foreach ($this->booking->invoiceGroupBookings() ?: collect([$this->booking]) as $leg) {
            $paid += (float) $leg->transactionsPaidTotal();
        }

        return new Content(view: 'emails.booking-cancellation', with: [
            'booking' => $this->booking,
            'paid' => round($paid, 2),
            'company' => \App\Support\InvoiceProfile::companyFor($this->booking->vatInvoiceRequested()),
            'contactEmail' => $this->booking->senderAddress(),
        ]);
    }
}
