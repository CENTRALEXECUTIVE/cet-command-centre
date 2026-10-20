<?php

namespace App\Mail;

use App\Models\Booking;
use App\Services\Payments\InvoicePdf;
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
    ) {
        // The PVT LTD company sends through its own mailer, kept separate.
        if ($name = $booking->pvtMailer()) {
            $this->mailer($name);
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new \Illuminate\Mail\Mailables\Address($this->booking->senderAddress(), $this->booking->senderName()),
            subject: 'Central Executive Transfers booking confirmation '.($this->booking->external_reference ?: $this->booking->reference),
        );
    }

    public function content(): Content
    {
        $tipsLive = app(\App\Services\Payments\SquareTipService::class)->enabled();
        // VAT only when the office selected a VAT invoice. Otherwise the booking is
        // the sister company, Central Executive Transfers PVT LTD (NOT VAT
        // registered) — so no VAT number, no VAT split, just the price.
        $isVat = $this->booking->vatInvoiceRequested();

        return new Content(view: 'emails.booking-confirmation', with: [
            'booking' => $this->booking,
            'paid' => $this->paid,
            'payUrl' => $this->payUrl,
            'vat' => $isVat ? $this->booking->fareVatBreakdown() : null,
            'vatNumber' => $isVat ? app(VatService::class)->number() : null,
            'company' => \App\Support\InvoiceProfile::companyFor($isVat),
            'tipUrl' => $tipsLive ? $this->booking->tipUrl() : null,
        ]);
    }

    /**
     * Attach the invoice PDF ONLY once the booking is paid — a pending booking
     * gets just the confirmation + Pay now link, no invoice. (ETO only invoiced
     * after payment too.)
     */
    public function attachments(): array
    {
        if (! $this->paid) {
            return [];
        }

        $ref = $this->booking->external_reference ?: $this->booking->reference;

        // The premium Inter-set invoice (pdf.receipt) — the same design used for
        // the office copy and corporate invoices, so the customer's attachment
        // looks like a proper VAT/invoice document, not the old plain receipt.
        try {
            $pdf = app(InvoicePdf::class)->renderReceipt($this->booking);
        } catch (\Throwable) {
            return []; // never let a PDF hiccup block the confirmation email
        }

        return [
            Attachment::fromData(fn () => $pdf, 'Central Executive Transfers - Invoice '.$ref.'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
