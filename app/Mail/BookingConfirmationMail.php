<?php

namespace App\Mail;

use App\Models\Booking;
use App\Services\Payments\BookingInvoicePdf;
use App\Services\Payments\VatService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Confirmation / receipt email to a CUSTOMER who booked through our own booking
 * system, in the shape ETO used: journey + customer + reservation + payment
 * status, the "important information" block, and a VAT invoice PDF attached.
 *
 * Only ever used for web bookings (source='web'), dispatched from the public
 * booking flow — never sent to ETO/imported customers.
 */
class BookingConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Booking $booking,
        public bool $paid = false,
        public ?string $payUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Central Executive Transfers booking confirmation '.($this->booking->external_reference ?: $this->booking->reference),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.booking-confirmation', with: [
            'booking' => $this->booking,
            'paid' => $this->paid,
            'payUrl' => $this->payUrl,
            'vat' => $this->booking->fareVatBreakdown(),
            'vatNumber' => app(VatService::class)->number(),
            'company' => (array) config('cet.company'),
        ]);
    }

    /** Attach the VAT invoice / receipt PDF, like ETO did. */
    public function attachments(): array
    {
        $ref = $this->booking->external_reference ?: $this->booking->reference;

        try {
            $pdf = app(BookingInvoicePdf::class)->render($this->booking);
        } catch (\Throwable) {
            return []; // never let a PDF hiccup block the confirmation email
        }

        return [
            Attachment::fromData(fn () => $pdf, 'Central Executive Transfers - Invoice '.$ref.'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
