<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A simple, branded acknowledgement to a customer — e.g. "we've received your
 * account registration" or "we've received your change request". Reassuring,
 * no action required; the office handles the follow-up.
 */
class CustomerNoticeMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param  array<int,string>  $lines */
    public function __construct(
        public string $subjectLine,
        public string $heading,
        public string $lead,
        public array $lines = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.customer-notice', with: [
            'heading' => $this->heading,
            'lead' => $this->lead,
            'lines' => $this->lines,
        ]);
    }
}
