<?php

namespace App\Services\Mail;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;

/**
 * Sends email through Resend's HTTPS API for the PVT LTD company. The host blocks
 * outbound SMTP, and (unlike the Gmail API route) this needs no Google Cloud
 * project or service account — just a Resend API key and the PVT domain verified
 * in Resend via Cloudflare DNS. Sending only; the PVT Workspace still receives.
 *
 * Silent no-op (returns false) until an API key is set.
 */
class ResendClient
{
    private const API = 'https://api.resend.com/emails';

    /** The last Resend error from a failed send, surfaced on the test. */
    public ?string $lastError = null;

    public function apiKey(): string
    {
        return (string) Setting::get('mail_pvt_resend_key', '');
    }

    public function configured(): bool
    {
        return filled($this->apiKey());
    }

    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @param  list<array{filename:string,content:string}>  $attachments  content = base64
     */
    public function send(string $from, array $to, string $subject, string $html, array $cc = [], ?string $replyTo = null, array $attachments = []): bool
    {
        $this->lastError = null;
        if (! $this->configured()) {
            $this->lastError = 'Resend API key not set.';

            return false;
        }

        $payload = array_filter([
            'from' => $from,
            'to' => array_values($to),
            'subject' => $subject,
            'html' => $html !== '' ? $html : '&nbsp;',
            'cc' => $cc !== [] ? array_values($cc) : null,
            'reply_to' => $replyTo ?: null,
            'attachments' => $attachments !== [] ? $attachments : null,
        ], fn ($v) => $v !== null && $v !== []);

        try {
            $response = Http::withToken($this->apiKey())->asJson()->post(self::API, $payload);
        } catch (\Throwable $e) {
            $this->lastError = 'Network error reaching Resend: '.$e->getMessage();

            return false;
        }

        if ($response->successful()) {
            return true;
        }

        $msg = (string) (data_get($response->json(), 'message') ?: $response->body());
        $this->lastError = 'HTTP '.$response->status().' — '.substr($msg, 0, 240);

        return false;
    }
}
