<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Backup for a website press that saved NO booking (repeat-click mismatch, a crash, …):
 * a critical alert to the directors AND an email to the office with every detail the
 * customer typed, so the trip can be booked by hand (27 Nov 2026). VAT ticked → admin@,
 * otherwise the PVT inbox (directors' rule). Never passwords or tokens. Spam-bot
 * submissions (the hidden honeypot field filled) never trigger it.
 */
class UnsavedBookingBackup
{
    public static function isSpam(Request $request): bool
    {
        return filled($request->input('company'));
    }

    public static function send(Request $request, string $why): void
    {
        if (self::isSpam($request)) {
            return;
        }
        $name = trim($request->input('customer_first_name').' '.$request->input('customer_surname')) ?: trim((string) $request->input('customer_name'));

        try {
            app(\App\Services\Watchdog\AdminAlerts::class)->notifyDirectors('price_on_request',
                '⚠ Website booking NOT saved — '.\Illuminate\Support\Str::limit($name ?: 'customer', 40),
                'A customer pressed Book on the website but no booking was created ('.$why.'). Their details are in Booking log and in the office email: book it by hand.',
                'critical');
        } catch (\Throwable) {
        }

        try {
            $main = trim((string) config('cet.ops_email')) ?: null;
            $pvt = \App\Models\Setting::get('mail_pvt_enabled', false)
                ? trim((string) (\App\Models\Setting::get('mail_pvt_from_address') ?: config('cet.company_novat.email'))) : '';
            $to = $request->boolean('vat_invoice') ? $main : ($pvt !== '' ? $pvt : $main);
            if (! $to) {
                return;
            }
            $fields = collect($request->except(['password', 'password_confirmation', '_token', 'submission_token', 'company', 'viewport']))
                ->filter(fn ($v) => is_scalar($v) && trim((string) $v) !== '');
            if (filled($fields->get('vehicle_type_id'))) {
                $fields->put('vehicle', \App\Models\VehicleType::whereKey((int) $fields->get('vehicle_type_id'))->value('name'));
            }
            $body = "A customer pressed Book on the website but NO booking was saved ({$why}).\n"
                ."Everything they typed is below. Book it by hand (Booking log → Make booking from this) and confirm with them.\n\n"
                .$fields->map(fn ($v, $k) => str_pad(ucfirst(str_replace('_', ' ', $k)).':', 26).$v)->implode("\n");
            AfterResponse::run(function () use ($to, $name, $body) {
                try {
                    \Illuminate\Support\Facades\Mail::raw($body, fn ($m) => $m->to($to)->subject('URGENT: website booking NOT saved — '.($name ?: 'customer')));
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('[booking-backup] email failed: '.$e->getMessage());
                }
            });
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[booking-backup] '.$e->getMessage());
        }
    }
}
