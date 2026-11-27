<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One outreach email, sent ONLY from an approved daily batch on /outreach/batch
 * (a director reviews and taps Approve). The body is the house-style draft that
 * ends at "Kind regards,"; the Matthew Green signature + CENTRAL logo is added
 * here, because the directors' mail app is not involved.
 */
class OutreachMail extends Mailable
{
    use Queueable, SerializesModels;

    /** $inReplyTo: the Message-ID of the email we're answering, so it threads in their inbox. */
    /**
     * $fromName / $signature: a personal email sent as a director (e.g. "Abdi") instead
     * of the usual Matthew Green outreach name and signature (directors, 27 Nov 2026).
     */
    public function __construct(
        public string $subjectLine,
        public string $bodyText,
        public ?string $inReplyTo = null,
        public ?string $fromName = null,
        public ?array $signature = null,
    ) {}

    public function headers(): \Illuminate\Mail\Mailables\Headers
    {
        return new \Illuminate\Mail\Mailables\Headers(
            text: $this->inReplyTo ? ['In-Reply-To' => $this->inReplyTo, 'References' => $this->inReplyTo] : [],
        );
    }

    public function envelope(): Envelope
    {
        $from = (string) config('mail.from.address');

        return new Envelope(
            from: new Address($from, $this->fromName ?? config('outreach.batch.from_name', 'Matthew Green | Central Executive Transfers')),
            replyTo: [new Address($from, 'Central Executive Transfers')],
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.outreach',
            text: 'emails.outreach-text',
            with: [
                'bodyText' => $this->bodyText,
                'sig' => $this->signature !== null ? array_merge((array) config('outreach.batch.signature', []), $this->signature) : (array) config('outreach.batch.signature', []),
                // Follow-ups/replies carry the open-account link; first cold emails keep
                // just the signature link (fewer links = safer with spam filters).
                'isFollowUp' => $this->inReplyTo !== null || str_starts_with(strtolower(ltrim($this->subjectLine)), 're:'),
            ],
        );
    }
}
