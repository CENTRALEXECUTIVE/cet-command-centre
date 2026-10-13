<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Office-editable SMTP settings. Entered in Settings → Email (web form) and stored
 * in the `settings` table, then MERGED over config('mail.*') at boot (see
 * AppServiceProvider) — so the operator can turn email on WITHOUT touching the
 * server .env, exactly like the Square/Stripe keys. An unset host leaves the
 * .env/config defaults (e.g. the "log" mailer) untouched.
 */
class MailSettings
{
    /** Merge stored SMTP settings over config('mail.*'). Safe to call every request. */
    public static function apply(): void
    {
        // The non-VAT sister company (PVT LTD) sends through its OWN mailer —
        // e.g. Google Workspace SMTP — kept completely separate from the VAT
        // company's sender. Configured here as a named 'pvt' mailer; the Mailables
        // pick it for chauffeurs bookings.
        $pvtHost = Setting::get('mail_pvt_host', null);
        if (filled($pvtHost)) {
            config(['mail.mailers.pvt' => array_filter([
                'transport' => 'smtp',
                'host' => (string) $pvtHost,
                'port' => (int) (Setting::get('mail_pvt_port', 587) ?: 587),
                'username' => (string) Setting::get('mail_pvt_username', ''),
                'password' => (string) Setting::get('mail_pvt_password', ''),
                'scheme' => Setting::get('mail_pvt_scheme', null) ?: null,
            ], fn ($v) => $v !== null)]);
        }

        // PVT LTD via the Gmail API (HTTPS) — the reliable route when the host
        // blocks SMTP. Registered as the 'gmail' mailer (transport in AppServiceProvider).
        config(['mail.mailers.gmail' => ['transport' => 'gmail']]);

        // Microsoft 365 (Graph): send through the connected mailbox — your own
        // domain, no branding. The GraphTransport is registered in AppServiceProvider.
        if (Setting::get('mail_transport', null) === 'graph') {
            config([
                'mail.default' => 'graph',
                'mail.mailers.graph' => ['transport' => 'graph'],
            ]);
            // The message is sent AS the Graph mailbox, so show that as the From.
            $from = Setting::get('mail_from_address', null) ?: config('services.microsoft_graph.mailbox');
            if (filled($from)) {
                config(['mail.from.address' => (string) $from]);
            }
            if ($fromName = Setting::get('mail_from_name', null)) {
                config(['mail.from.name' => (string) $fromName]);
            }

            return;
        }

        $host = Setting::get('mail_host', null);
        if ($host === null || trim((string) $host) === '') {
            return; // no in-app SMTP configured — keep .env/config as-is
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => (string) $host,
            'mail.mailers.smtp.port' => (int) (Setting::get('mail_port', 465) ?: 465),
            'mail.mailers.smtp.username' => (string) Setting::get('mail_username', ''),
            'mail.mailers.smtp.password' => (string) Setting::get('mail_password', ''),
        ]);

        // Same-server cPanel sending: the local mail server's TLS certificate is
        // for the box's real hostname, not "localhost", so verifying the peer cert
        // would fail. The traffic never leaves the machine, so it's safe not to
        // verify when sending via localhost.
        if (in_array(strtolower(trim((string) $host)), ['localhost', '127.0.0.1', '::1'], true)) {
            config(['mail.mailers.smtp.verify_peer' => 0]);
        }

        // Scheme: smtps for 465 (implicit TLS), smtp for 587 (STARTTLS). Blank lets
        // Symfony auto-detect from the port.
        if ($scheme = Setting::get('mail_scheme', null)) {
            config(['mail.mailers.smtp.scheme' => (string) $scheme]);
        }
        if ($from = Setting::get('mail_from_address', null)) {
            config(['mail.from.address' => (string) $from]);
        }
        if ($fromName = Setting::get('mail_from_name', null)) {
            config(['mail.from.name' => (string) $fromName]);
        }
    }

    /** The effective values for the settings form (saved value, else the config default). */
    public static function current(): array
    {
        return [
            'transport' => (string) (Setting::get('mail_transport', '') ?: 'smtp'),
            'graph_configured' => app(\App\Services\Inbox\GraphMailClient::class)->configured(),
            'graph_mailbox' => (string) config('services.microsoft_graph.mailbox'),
            'pvt_enabled' => (bool) Setting::get('mail_pvt_enabled', false),
            // Gmail API ready = a Google service-account key is present (the robust,
            // SMTP-free route for PVT). Whether it's actually authorised in the PVT
            // Workspace only shows up when a test is sent.
            'pvt_gmail_configured' => app(\App\Services\Mail\GmailApiClient::class)->configured(),
            'pvt_gmail_client_id' => (string) app(\App\Services\Mail\GmailApiClient::class)->clientId(),
            'pvt_gmail_client_email' => (string) app(\App\Services\Mail\GmailApiClient::class)->clientEmail(),
            'pvt_from_address' => (string) (Setting::get('mail_pvt_from_address', '') ?: config('cet.company_novat.email')),
            'pvt_host' => (string) Setting::get('mail_pvt_host', ''),
            'pvt_port' => (string) Setting::get('mail_pvt_port', ''),
            'pvt_scheme' => (string) Setting::get('mail_pvt_scheme', ''),
            'pvt_username' => (string) Setting::get('mail_pvt_username', ''),
            'pvt_has_password' => filled(Setting::get('mail_pvt_password', '')),
            'host' => (string) Setting::get('mail_host', ''),
            'port' => (string) Setting::get('mail_port', ''),
            'scheme' => (string) Setting::get('mail_scheme', ''),
            'username' => (string) Setting::get('mail_username', ''),
            'from_address' => (string) (Setting::get('mail_from_address', '') ?: config('mail.from.address')),
            'from_name' => (string) (Setting::get('mail_from_name', '') ?: config('mail.from.name')),
            // Never expose the stored password — only whether one is set.
            'has_password' => filled(Setting::get('mail_password', '')),
        ];
    }
}
