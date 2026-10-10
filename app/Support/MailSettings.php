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
