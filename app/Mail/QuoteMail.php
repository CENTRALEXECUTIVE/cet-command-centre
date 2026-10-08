<?php

namespace App\Mail;

use App\Models\Quote;
use App\Services\Payments\VatService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A price quote emailed to a customer on request (office-initiated from the
 * Instant Quote page). Never automatic.
 */
class QuoteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Quote $quote,
        public bool $withVat = false,
        public ?string $toName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Central Executive Transfers — Your quote '.$this->quote->reference);
    }

    public function content(): Content
    {
        $net = (float) $this->quote->price;
        $vat = app(VatService::class);
        $applyVat = $this->withVat && $vat->registered();

        return new Content(view: 'emails.quote', with: [
            'quote' => $this->quote,
            'toName' => $this->toName,
            'applyVat' => $applyVat,
            'net' => $net,
            'vatAmount' => $applyVat ? $vat->fromNet($net)['vat'] : 0.0,
            'gross' => $applyVat ? $vat->fromNet($net)['gross'] : $net,
            'ratePercent' => $vat->ratePercent(),
        ]);
    }
}
