<?php

namespace App\Mail\Concerns;

/**
 * Makes a mailable REALLY send through the mailer it picks (the PVT LTD sender).
 *
 * Laravel's Mail::to()->send($mailable) overwrites a mailable's ->mailer() with the
 * default mailer before sending, so PVT emails silently went out through the main
 * (Microsoft 365 / Ltd) mailbox while Settings showed "via Resend" (found 21 Nov
 * 2026, CET-4DA06B). Pin the name here and send() honours it.
 */
trait PinsMailer
{
    protected ?string $pinnedMailer = null;

    protected function pinMailer(string $name): void
    {
        $this->pinnedMailer = $name;
        $this->mailer($name);
    }

    public function send($mailer)
    {
        if ($this->pinnedMailer === null) {
            return parent::send($mailer);
        }

        $this->mailer = $this->pinnedMailer;
        try {
            return parent::send(app('mail.manager')->mailer($this->pinnedMailer));
        } catch (\Throwable $e) {
            // The PVT sender is down (e.g. Resend outage): never leave the customer
            // without their email — send it through the main mailer, and log loudly.
            \Illuminate\Support\Facades\Log::warning('PVT mailer ['.$this->pinnedMailer.'] failed, sent via main instead: '.$e->getMessage());

            return parent::send($mailer);
        }
    }
}
