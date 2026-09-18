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
            'customerLine' => Setting::get('twilio_customer_line') ?: config('services.twilio_masking.customer_line'),
            'driverLine' => Setting::get('twilio_driver_line') ?: config('services.twilio_masking.driver_line'),
            'prevLine' => Setting::get('twilio_customer_line_prev'),
            'cutover' => Setting::get('twilio_customer_line_cutover'),
            'smsWebhook' => $base.'/webhooks/sms'.$suffix,
            'voiceWebhook' => $base.'/webhooks/voice'.$suffix,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'google_maps_key' => ['nullable', 'string', 'max:120'],
            'twilio_customer_line' => ['nullable', 'string', 'max:32'],
            'twilio_driver_line' => ['nullable', 'string', 'max:32'],
        ]);

        Setting::set('google_maps_key', trim((string) ($data['google_maps_key'] ?? '')), 'string', 'integrations');
        Setting::set('twilio_customer_line', trim((string) ($data['twilio_customer_line'] ?? '')), 'string', 'telephony');
        Setting::set('twilio_driver_line', trim((string) ($data['twilio_driver_line'] ?? '')), 'string', 'telephony');

        return back()->with('status', 'Settings saved.');
    }
}
