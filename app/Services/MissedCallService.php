<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\MissedCall;
use App\Models\WatchdogEvent;
use App\Support\Phone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Missed-call handling: every missed call to the CET line is logged and the
 * office is alerted at once with a ready-written WhatsApp reply to tap — so no
 * potential booking is lost. Nothing is sent to the caller automatically
 * (CLAUDE.md rule 2: customer messages are manual, via wa.me links). This used
 * to auto-send a WhatsApp through Twilio whenever Twilio WhatsApp was set up.
 */
class MissedCallService
{
    public function handle(string $fromNumber, ?Carbon $receivedAt = null): MissedCall
    {
        $customer = Customer::where('phone', $fromNumber)->first();

        $call = MissedCall::create([
            'from_number' => $fromNumber,
            'customer_id' => $customer?->id,
            'received_at' => $receivedAt ?? now(),
        ]);

        $name = $customer ? ' '.Str::before($customer->name, ' ') : '';
        $draft = "Hi{$name}, sorry we missed your call to Central Executive Transfers. "
            .'Reply here with your pickup, destination and time and we’ll sort your booking right away.';
        $wa = Phone::wa($fromNumber);

        WatchdogEvent::log(
            'missed_call',
            '📞 Missed call from '.($customer?->name ? $customer->name.' ('.$fromNumber.')' : $fromNumber).' — call back within 5 minutes.',
            'warning',
            null,
            body: $wa
                ? "Call back, or tap to WhatsApp them: https://wa.me/{$wa}?text=".rawurlencode($draft)
                : 'Call back as soon as possible.',
        );

        return $call;
    }
}
