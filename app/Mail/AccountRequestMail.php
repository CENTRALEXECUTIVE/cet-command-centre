<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Internal "new account sign-up" notification to the office — personal or business.
 * Not a customer email; goes to the ops inbox only, so a new sign-up is never missed
 * (push can be off or muted). Mirrors OfficeBookingMail.
 */
class AccountRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  'company'|'personal'  $type
     * @param  array<string,string>  $details  label => value rows for the body
     */
    public function __construct(
        public string $type,
        public string $name,
        public array $details,
        public ?string $reviewUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->type === 'company'
                ? 'New business account request — '.$this->name
                : 'New personal account — '.$this->name,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.account-request', with: [
            'type' => $this->type,
            'name' => $this->name,
            'details' => $this->details,
            'reviewUrl' => $this->reviewUrl,
        ]);
    }
}
