<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A customer message (reminder, review request, one-off) emailed BY HAND from
 * the booking page — for customers who don't use WhatsApp. Always operator-
 * triggered per message; nothing here sends automatically or in bulk. The body
 * is the same text as the WhatsApp version, with the WhatsApp *bold* markers
 * stripped so it reads cleanly as an email.
 */
class CustomerMessageMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $subjectLine, public string $textBody) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(text: 'emails.plain-message', with: [
            'textBody' => $this->textBody,
        ]);
    }
}
