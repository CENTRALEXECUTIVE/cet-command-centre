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
        // An office-sent "your booking has been updated" copy (after an edit or
        // re-price) — same email, an "Updated" subject and banner. Never automatic.
        public bool $updated = false,
        // Unsaved legs for the New Booking form's preview (nothing is in the DB
        // yet), so a return previews as the same combined email it will send.
        public ?\Illuminate\Support\Collection $previewLegs = null,
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
            subject: ($this->updated ? 'Updated: Central Executive Transfers booking confirmation ' : 'Central Executive Transfers booking confirmation ')
                .($this->booking->external_reference ?: $this->booking->reference),
        );
    }

    public function content(): Content
    {
        $tipsLive = app(\App\Services\Payments\SquareTipService::class)->enabled();
        // VAT only when the office selected a VAT invoice. Otherwise the booking is
        // the sister company, Central Executive Transfers PVT LTD (NOT VAT
        // registered) — so no VAT number, no VAT split, just the price.
        $isVat = $this->booking->vatInvoiceRequested();

        // On a return trip the pay link charges the WHOLE trip (both legs) in one go,
        // even though this confirmation shows just this leg's fare — so pass the trip
        // total to explain the button ("covers your full return trip — £220").
        $group = $this->previewLegs ?? $this->booking->tripBookings();
        $tripPayTotal = null;
        if (! $this->paid && $this->payUrl && $group->count() > 1) {
            $outstanding = round($group->sum(fn ($b) => (float) ($b->transactionsAmountDue() ?? $b->amountPayable() ?? 0)), 2);
            $own = (float) ($this->booking->transactionsAmountDue() ?? $this->booking->amountPayable() ?? 0);
            $tripPayTotal = $outstanding > $own + 0.01 ? $outstanding : null;
        }

        // A return / multi-leg trip: show EVERY live journey (date order, the same
        // whichever leg it's sent from) and the TRIP money — each leg, total, paid,
        // remaining, VAT. Cancelled legs are listed apart and never charged.
        $trip = null;
        $all = $this->previewLegs ?? $this->booking->invoiceGroupBookings();
        $cancelledLegs = $all->count() > 1
            ? $all->filter(fn ($b) => in_array($b->status, [\App\Enums\BookingStatus::Cancelled, \App\Enums\BookingStatus::NoShow], true))->values()
            : collect();
        if ($group->count() > 1) {
            $legs = $group->sortBy(fn ($b) => $b->pickup_at?->timestamp ?? 0)->values();
            $isPair = $legs->count() === 2 && $legs->contains(fn ($b) => $b->is_return_leg);
            $title = fn ($b, $i) => $isPair ? ($b->is_return_leg ? 'Return journey' : 'Outbound journey') : 'Journey '.($i + 1);
            // "UPDATED" marks a leg edited since the customer was last emailed.
            $lastSent = fn ($b) => $b->meta['customer_email_sent'] ?? null;
            $changed = fn ($b) => $this->updated && filled($b->meta['last_edited_at'] ?? null)
                && (! $lastSent($b) || \Illuminate\Support\Carbon::parse($b->meta['last_edited_at'])->gt(\Illuminate\Support\Carbon::parse($lastSent($b))));
            $vatNet = 0.0;
            $vatAmt = 0.0;
            foreach ($legs as $leg) {
                if ($leg->vatInvoiceRequested() && ($v = $leg->fareVatBreakdown())) {
                    $vatNet += (float) $v['net'];
                    $vatAmt += (float) $v['vat'];
                }
            }
            $trip = [
                'refs' => $legs->map(fn ($b) => $b->external_reference ?: $b->reference)->implode(' / '),
                'legs' => $legs->map(fn ($b, $i) => ['booking' => $b, 'title' => $title($b, $i), 'changed' => $changed($b)])->all(),
                'lines' => $legs->map(fn ($b, $i) => [
                    'label' => ($isPair ? ($b->is_return_leg ? 'Return' : 'Outbound') : 'Journey '.($i + 1)).' — '.($b->pickup_at?->format('D d M, H:i') ?? ''),
                    'amount' => (float) ($b->amountPayable() ?? 0),
                ])->all(),
                'total' => round($legs->sum(fn ($b) => (float) ($b->amountPayable() ?? 0)), 2),
                'paid' => round($legs->sum(fn ($b) => (float) $b->transactionsPaidTotal()), 2),
                'due' => round($legs->sum(fn ($b) => (float) ($b->transactionsAmountDue() ?? 0)), 2),
                'vat' => $vatAmt > 0 ? ['vat' => round($vatAmt, 2), 'net' => round($vatNet, 2)] : null,
            ];
        }

        return new Content(view: 'emails.booking-confirmation', with: [
            'trip' => $trip,
            'cancelledLegs' => $cancelledLegs,
            'booking' => $this->booking,
            'paid' => $this->paid,
            'payUrl' => $this->payUrl,
            'updated' => $this->updated,
            'tripPayTotal' => $tripPayTotal,
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
