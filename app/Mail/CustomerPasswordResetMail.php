<?php

namespace App\Mail;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The one-hour password-reset link for a customer who asked to reset their own
 * My Account password. Strictly user-initiated and transactional.
 */
class CustomerPasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Customer $customer,
        public string $link,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your Central Executive Transfers password');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.customer-password-reset', with: [
            'name' => $this->customer->name,
            'link' => $this->link,
        ]);
    }
}
