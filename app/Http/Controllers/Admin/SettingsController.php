<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        ]);
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
        ]);

        Setting::set('google_maps_key', trim((string) ($data['google_maps_key'] ?? '')), 'string', 'integrations');
        Setting::set('getaddress_key', trim((string) ($data['getaddress_key'] ?? '')), 'string', 'integrations');
        Setting::set('twilio_customer_line', trim((string) ($data['twilio_customer_line'] ?? '')), 'string', 'telephony');
        Setting::set('twilio_driver_line', trim((string) ($data['twilio_driver_line'] ?? '')), 'string', 'telephony');
        Setting::set('unbranded_link_base', trim((string) ($data['unbranded_link_base'] ?? '')), 'string', 'integrations');

        foreach ([
            'invoice_company_address', 'invoice_vat_number', 'invoice_phone', 'invoice_email',
            'invoice_bank_name', 'invoice_bank_sort', 'invoice_bank_account', 'invoice_footer_note',
        ] as $key) {
            Setting::set($key, trim((string) ($data[$key] ?? '')), 'string', 'invoicing');
        }

        return back()->with('status', 'Settings saved.');
    }
}
