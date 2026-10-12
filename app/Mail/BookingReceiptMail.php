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

    /**
     * @param  bool|null  $pvt  Whether this invoice is billed by the NON-VAT sister
     *   company (PVT LTD) and so must send from its own address/mailer. Null =>
     *   follow the booking's billing entity; a cover invoice passes it explicitly
     *   (its PVT status comes from the payment provider, not the billing entity).
     */
    public function __construct(
        public Booking $booking,
        public string $pdf,
        public ?bool $pvt = null,
    ) {
        // Keep the two companies' email separate: a PVT invoice sends through the
        // PVT LTD mailer when PVT sending is set up (falls back to the main mailer
        // until then, so nothing breaks before PVT email is live).
        if ($this->booking->usesPvtMailerFor($this->isPvt())) {
            $this->mailer('pvt');
        }
    }

    /** Resolve the PVT decision: explicit flag, else the booking's billing entity. */
    private function isPvt(): bool
    {
        return $this->pvt ?? ($this->booking->billingEntity() === 'chauffeurs');
    }

    public function envelope(): Envelope
    {
        $kind = $this->booking->vatInvoiceRequested() ? 'VAT Invoice' : 'Invoice';

        return new Envelope(
            from: new \Illuminate\Mail\Mailables\Address(
                $this->booking->senderAddressFor($this->isPvt()),
                $this->booking->senderName(),
            ),
            subject: "Central Executive Transfers — {$kind} {$this->booking->reference}",
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.booking-receipt', with: [
            'booking' => $this->booking,
            'isCover' => $this->booking->isCoverJob(),
            'isVat' => $this->booking->vatInvoiceRequested(),
        ]);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $kind = 'Invoice';

        return [
            Attachment::fromData(fn () => $this->pdf, "{$kind}-{$this->booking->reference}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
