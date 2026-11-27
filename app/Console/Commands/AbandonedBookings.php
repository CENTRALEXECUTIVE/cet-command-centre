<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\BookingAttempt;
use App\Services\Watchdog\AdminAlerts;
use Illuminate\Console\Command;

/**
 * A customer filled in their details on the website and left WITHOUT booking (27 Nov
 * 2026). If no booking has arrived from them 15 minutes later, tell the office so they
 * can call or email — with the details from the booking log. Each entry alerts once.
 */
class AbandonedBookings extends Command
{
    protected $signature = 'cet:abandoned-bookings';

    protected $description = 'Alert the office about website forms filled in but left without booking';

    public function handle(AdminAlerts $alerts): int
    {
        try {
            $rows = BookingAttempt::where('outcome', 'abandoned')
                ->whereBetween('created_at', [now()->subHours(6), now()->subMinutes(15)])
                ->orderBy('id')->limit(50)->get();
        } catch (\Throwable) {
            return self::SUCCESS; // not migrated yet
        }

        foreach ($rows as $a) {
            $d = (array) ($a->details ?? []);
            if (! empty($d['alerted'])) {
                continue;
            }
            $form = (array) ($d['form'] ?? []);
            $email = strtolower(trim((string) ($form['customer_email'] ?? '')));
            $phone = preg_replace('/\D/', '', (string) ($form['customer_phone'] ?? ''));
            // Did they book after all (same email or phone, since they left)?
            $booked = Booking::where('created_at', '>=', $a->created_at->copy()->subMinutes(30))
                ->whereHas('customer', function ($q) use ($email, $phone) {
                    $q->where(function ($w) use ($email, $phone) {
                        if ($email !== '') {
                            $w->orWhereRaw('LOWER(email) = ?', [$email]);
                        }
                        if (strlen($phone) >= 9) {
                            $w->orWhere('phone', 'like', '%'.substr($phone, -9));
                        }
                    });
                })->exists();
            $d['alerted'] = now()->toIso8601String();
            $a->forceFill(['details' => $d])->save();
            if ($booked || ($email === '' && strlen($phone) < 9)) {
                continue;
            }
            $title = '👋 Started a booking but didn\'t finish — '.\Illuminate\Support\Str::limit($a->customer_name ?: ($email ?: 'customer'), 40);
            \App\Models\WatchdogEvent::log('web_booking', $title, 'warning');
            $alerts->notify('web_booking', $title,
                trim(($a->customer_name ?: 'A customer').' filled in '.\Illuminate\Support\Str::limit((string) $a->pickup, 40).' → '
                    .\Illuminate\Support\Str::limit((string) $a->destination, 40).($a->pickup_at ? ' ('.$a->pickup_at.')' : '')
                    .' and left without booking. Contact: '.($a->contact ?: '—').'. Booking log → Make booking from this.'),
                'warning');
        }

        return self::SUCCESS;
    }
}
