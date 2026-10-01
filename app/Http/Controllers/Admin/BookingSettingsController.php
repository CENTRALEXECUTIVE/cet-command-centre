<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\BookingSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * One place for the office to tune how the booking system behaves — minimum online
 * notice, estate uplift, VAT, driver pay, review timing, policy links and ops email.
 * Values are stored in `settings` and merged over config('cet.*') at boot.
 */
class BookingSettingsController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        return view('admin.booking-settings.index', ['s' => BookingSettings::current()]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'min_lead_hours' => ['required', 'integer', 'min:0', 'max:168'],
            'estate_uplift' => ['required', 'numeric', 'min:0', 'max:1000'],
            'review_delay_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'vat_registered' => ['nullable', 'boolean'],
            'vat_rate_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'driver_pay_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'terms_url' => ['nullable', 'url', 'max:300'],
            'privacy_url' => ['nullable', 'url', 'max:300'],
            'cancellation_url' => ['nullable', 'url', 'max:300'],
            'ops_email' => ['nullable', 'email', 'max:160'],
        ]);

        Setting::set('booking.min_lead_hours', (int) $data['min_lead_hours'], 'int', 'booking');
        Setting::set('booking.estate_uplift', (float) $data['estate_uplift'], 'string', 'booking');
        Setting::set('booking.review_delay_minutes', (int) $data['review_delay_minutes'], 'int', 'booking');
        Setting::set('booking.vat_registered', $request->boolean('vat_registered'), 'bool', 'booking');
        Setting::set('booking.vat_rate_percent', (float) $data['vat_rate_percent'], 'string', 'booking');
        Setting::set('driver_pay_percent', (float) $data['driver_pay_percent'], 'string', 'booking');
        Setting::set('booking.terms_url', (string) ($data['terms_url'] ?? ''), 'string', 'booking');
        Setting::set('booking.privacy_url', (string) ($data['privacy_url'] ?? ''), 'string', 'booking');
        Setting::set('booking.cancellation_url', (string) ($data['cancellation_url'] ?? ''), 'string', 'booking');
        Setting::set('booking.ops_email', (string) ($data['ops_email'] ?? ''), 'string', 'booking');

        // Reflect the new values immediately for the rest of this request.
        BookingSettings::apply();

        return back()->with('status', 'Booking settings saved.');
    }
}
