<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\MissedCall;
use App\Services\Messaging\WhatsAppService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Missed-call handling: every missed call to the CET line is logged and gets an
 * instant automated WhatsApp response, so no potential booking is ever lost.
 */
class MissedCallService
{
    public function __construct(private readonly WhatsAppService $whatsApp) {}

    public function handle(string $fromNumber, ?Carbon $receivedAt = null): MissedCall
    {
        $customer = Customer::where('phone', $fromNumber)->first();

        $call = MissedCall::create([
            'from_number' => $fromNumber,
            'customer_id' => $customer?->id,
            'received_at' => $receivedAt ?? now(),
        ]);

        $name = $customer ? ' '.Str::before($customer->name, ' ') : '';
        $body = "Hi{$name}, sorry we missed your call to Central Executive Transfers. "
            .'Reply to this message with your pickup, destination and time and we’ll sort your booking right away.';

        $message = $this->whatsApp->send($fromNumber, $body, ['type' => 'custom', 'customer' => $customer]);

        if ($message->status === 'sent') {
            $call->update(['auto_response_sent' => true, 'auto_response_sent_at' => now()]);
        }

        return $call;
    }
}
