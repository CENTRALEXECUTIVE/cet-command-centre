<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

/**
 * In-app settings so keys can be pasted without touching .env or the terminal.
 * Currently the Google Maps key (address autocomplete + distance pricing).
 */
class SettingsController extends Controller
{
    public function index(): View
    {
        $secret = (string) config('cet.webhook_secret');
        $base = rtrim((string) config('app.url'), '/');
        $suffix = $secret !== '' ? '?secret='.$secret : '?secret=YOUR_CET_WEBHOOK_SECRET';

        return view('admin.settings.index', [
            'mailer' => (string) config('mail.default'),
            'mailFrom' => (string) config('mail.from.address'),
            'opsEmail' => (string) config('cet.ops_email'),
            'mail' => \App\Support\MailSettings::current(),
            'mapsKey' => Setting::get('google_maps_key'),
            'getAddressKey' => Setting::get('getaddress_key'),
            'customerLine' => Setting::get('twilio_customer_line') ?: config('services.twilio_masking.customer_line'),
            'driverLine' => Setting::get('twilio_driver_line') ?: config('services.twilio_masking.driver_line'),
            'prevLine' => Setting::get('twilio_customer_line_prev'),
            'prevNames' => Setting::get('twilio_customer_line_prev_names'),
            'unbrandedLinkBase' => Setting::get('unbranded_link_base'),
            'invoiceProfile' => [
                'address' => Setting::get('invoice_company_address'),
                'vat_number' => Setting::get('invoice_vat_number'),
                'phone' => Setting::get('invoice_phone'),
                'email' => Setting::get('invoice_email'),
                'bank_name' => Setting::get('invoice_bank_name'),
                'bank_sort' => Setting::get('invoice_bank_sort'),
                'bank_account' => Setting::get('invoice_bank_account'),
                'footer_note' => Setting::get('invoice_footer_note'),
            ],
            'smsWebhook' => $base.'/webhooks/sms'.$suffix,
            'voiceWebhook' => $base.'/webhooks/voice'.$suffix,
            'square' => [
                'environment' => Setting::get('square_environment') ?: config('services.square.environment', 'production'),
                'app_id' => Setting::get('square_app_id') ?: config('services.square.app_id'),
                'access_token' => Setting::get('square_access_token') ?: config('services.square.access_token'),
                'location_id' => Setting::get('square_location_id') ?: config('services.square.location_id'),
                'webhook_signature_key' => Setting::get('square_webhook_signature_key') ?: config('services.square.webhook_signature_key'),
                'chauffeurs_access_token' => Setting::get('square_chauffeurs_access_token') ?: config('services.square_chauffeurs.access_token'),
                'chauffeurs_location_id' => Setting::get('square_chauffeurs_location_id') ?: config('services.square_chauffeurs.location_id'),
                'webhook_url' => $base.'/webhooks/square',
                'chauffeurs_webhook_url' => $base.'/webhooks/square-chauffeurs',
            ],
            'stripe' => [
                'secret_key' => Setting::get('stripe_secret_key') ?: config('services.stripe.secret_key'),
                'publishable_key' => Setting::get('stripe_publishable_key') ?: config('services.stripe.publishable_key'),
                'webhook_secret' => Setting::get('stripe_webhook_secret') ?: config('services.stripe.webhook_secret'),
                'webhook_url' => $base.'/webhooks/stripe',
                'novat_company_name' => Setting::get('invoice_novat_company_name') ?: config('cet.company_novat.name'),
                'novat_company_number' => Setting::get('invoice_novat_company_number') ?: config('cet.company_novat.number'),
                'novat_company_address' => Setting::get('invoice_novat_company_address') ?: config('cet.company_novat.address'),
            ],
        ]);
    }

    /**
     * Send a plain test email to confirm the mail setup (SMTP) actually works,
     * before relying on it for booking confirmations. Goes to a given address or
     * the signed-in admin. Reports the live mailer so "log" (nothing sent) is
     * obvious.
     */
    public function sendTestEmail(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate(['to' => ['nullable', 'email']]);
        $to = $data['to'] ?? $request->user()->email ?? config('cet.ops_email');
        $mailer = (string) config('mail.default');

        if ($mailer === 'log') {
            return back()->with('error', 'Mail is set to "log" — emails are written to the log file, NOT sent. Set MAIL_MAILER=smtp (and the host/username/password) in the server .env, then try again.')->with('scroll', 'mail');
        }

        try {
            Mail::raw(
                "This is a test email from the CET Command Centre.\n\nIf you can read this, outgoing email is working — booking confirmations and invoices will send.\n\nSent ".now()->format('D d M Y, H:i').'.',
                function ($m) use ($to) {
                    $m->to($to)->subject('CET Command Centre — test email');
                }
            );
        } catch (\Throwable $e) {
            return back()->with('error', 'Test email failed: '.$e->getMessage().' — check the SMTP settings in .env.')->with('scroll', 'mail');
        }

        return back()->with('status', 'Test email sent to '.$to.' via "'.$mailer.'". Check the inbox (and spam). If it doesn’t arrive, the SMTP details are wrong.')->with('scroll', 'mail');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'google_maps_key' => ['nullable', 'string', 'max:120'],
            'getaddress_key' => ['nullable', 'string', 'max:120'],
            'twilio_customer_line' => ['nullable', 'string', 'max:32'],
            'twilio_driver_line' => ['nullable', 'string', 'max:32'],
            'unbranded_link_base' => ['nullable', 'url', 'max:120'],
            'invoice_company_address' => ['nullable', 'string', 'max:500'],
            'invoice_vat_number' => ['nullable', 'string', 'max:32'],
            'invoice_phone' => ['nullable', 'string', 'max:32'],
            'invoice_email' => ['nullable', 'email', 'max:160'],
            'invoice_bank_name' => ['nullable', 'string', 'max:120'],
            'invoice_bank_sort' => ['nullable', 'string', 'max:12'],
            'invoice_bank_account' => ['nullable', 'string', 'max:20'],
            'invoice_footer_note' => ['nullable', 'string', 'max:500'],
            'square_environment' => ['nullable', 'in:production,sandbox'],
            'square_app_id' => ['nullable', 'string', 'max:120'],
            'square_access_token' => ['nullable', 'string', 'max:255'],
            'square_location_id' => ['nullable', 'string', 'max:120'],
            'square_webhook_signature_key' => ['nullable', 'string', 'max:255'],
            'square_chauffeurs_access_token' => ['nullable', 'string', 'max:255'],
            'square_chauffeurs_location_id' => ['nullable', 'string', 'max:120'],
            'square_chauffeurs_webhook_signature_key' => ['nullable', 'string', 'max:255'],
            'stripe_secret_key' => ['nullable', 'string', 'max:255'],
            'stripe_publishable_key' => ['nullable', 'string', 'max:255'],
            'stripe_webhook_secret' => ['nullable', 'string', 'max:255'],
            'invoice_novat_company_name' => ['nullable', 'string', 'max:160'],
            'invoice_novat_company_number' => ['nullable', 'string', 'max:40'],
            'invoice_novat_company_address' => ['nullable', 'string', 'max:500'],
            'mail_host' => ['nullable', 'string', 'max:160'],
            'mail_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'mail_scheme' => ['nullable', 'in:smtp,smtps'],
            'mail_username' => ['nullable', 'string', 'max:160'],
            'mail_password' => ['nullable', 'string', 'max:255'],
            'mail_from_address' => ['nullable', 'email', 'max:160'],
            'mail_from_name' => ['nullable', 'string', 'max:120'],
        ]);

        // Only save a field when the submitting form actually included it, so a
        // single-section form (e.g. the Email panel) never wipes settings owned by
        // another form on the page.
        $save = function (string $key, string $group) use ($data) {
            if (array_key_exists($key, $data)) {
                Setting::set($key, trim((string) ($data[$key] ?? '')), 'string', $group);
            }
        };

        $save('google_maps_key', 'integrations');
        $save('getaddress_key', 'integrations');
        $save('twilio_customer_line', 'telephony');
        $save('twilio_driver_line', 'telephony');
        $save('unbranded_link_base', 'integrations');

        foreach ([
            'invoice_company_address', 'invoice_vat_number', 'invoice_phone', 'invoice_email',
            'invoice_bank_name', 'invoice_bank_sort', 'invoice_bank_account', 'invoice_footer_note',
            'invoice_novat_company_name', 'invoice_novat_company_number', 'invoice_novat_company_address',
        ] as $key) {
            $save($key, 'invoicing');
        }

        // In-app SMTP (Settings → Email): switch email on without the server .env.
        // Non-secret fields save as submitted (blank clears, disabling in-app SMTP);
        // the password is write-only so a blank submit never wipes a saved password.
        foreach (['mail_host', 'mail_scheme', 'mail_username', 'mail_from_address', 'mail_from_name'] as $key) {
            $save($key, 'mail');
        }
        if (array_key_exists('mail_port', $data)) {
            Setting::set('mail_port', trim((string) ($data['mail_port'] ?? '')), 'string', 'mail');
        }
        if (array_key_exists('mail_password', $data) && trim((string) ($data['mail_password'] ?? '')) !== '') {
            Setting::set('mail_password', trim((string) $data['mail_password']), 'string', 'mail');
        }

        // Square payment keys (in-app wins over .env). Only overwrite when a value was
        // provided, so leaving a secret field blank doesn't wipe a saved key.
        foreach ([
            'square_environment', 'square_app_id', 'square_access_token', 'square_location_id',
            'square_webhook_signature_key', 'square_chauffeurs_access_token',
            'square_chauffeurs_location_id', 'square_chauffeurs_webhook_signature_key',
            'stripe_secret_key', 'stripe_publishable_key', 'stripe_webhook_secret',
        ] as $key) {
            if (array_key_exists($key, $data) && trim((string) ($data[$key] ?? '')) !== '') {
                Setting::set($key, trim((string) $data[$key]), 'string', 'payments');
            }
        }

        return back()->with('status', 'Settings saved.');
    }
}
