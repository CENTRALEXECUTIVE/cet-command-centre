<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A single-booking receipt / VAT invoice emailed to the customer when the office
 * taps "Email to customer" on the booking. Deliberate, manual send only — never
 * automatic — with the PDF attached (passed in as raw bytes so nothing extra is
 * written to disk).
 */
class BookingReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Booking $booking,
        public string $pdf,
    ) {}

    public function envelope(): Envelope
    {
        $kind = $this->booking->vatInvoiceRequested() ? 'VAT Invoice' : 'Receipt';

        return new Envelope(
            subject: "Central Executive Transfers — {$kind} {$this->booking->reference}",
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.booking-receipt', with: [
            'booking' => $this->booking,
            'isVat' => $this->booking->vatInvoiceRequested(),
        ]);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $kind = $this->booking->vatInvoiceRequested() ? 'Invoice' : 'Receipt';

        return [
            Attachment::fromData(fn () => $this->pdf, "{$kind}-{$this->booking->reference}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
