<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** One-hour Command Centre password-reset link for a staff account. */
class StaffPasswordResetMail extends Mailable
{
    public function __construct(public User $user, public string $link) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Command Centre password reset for '.$this->user->name);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.staff-password-reset', with: ['user' => $this->user, 'link' => $this->link]);
    }
}
