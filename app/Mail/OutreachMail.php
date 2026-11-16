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
    public function __construct(public string $subjectLine, public string $bodyText, public ?string $inReplyTo = null) {}

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
            from: new Address($from, config('outreach.batch.from_name', 'Matthew Green | Central Executive Transfers')),
            replyTo: [new Address($from, 'Central Executive Transfers')],
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.outreach',
            text: 'emails.outreach-text',
            with: ['bodyText' => $this->bodyText, 'sig' => (array) config('outreach.batch.signature', [])],
        );
    }
}
