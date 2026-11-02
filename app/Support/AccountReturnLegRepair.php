<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One-off repair for web return trips booked by an ACCOUNT customer before the
 * widget fix: the outbound went on the account but the return leg was saved as
 * a card job off the account, so the monthly invoice missed it. Puts such return
 * legs on the same account as their outbound.
 *
 * Only touches a return leg that is still an UNPAID card job with no card
 * payment recorded, whose outbound is an account job. Prices, times, status and
 * the calendar are never touched; nothing is sent.
 */
class AccountReturnLegRepair
{
    /** @return array<int, string> references repaired */
    public static function run(): array
    {
        $fixed = [];

        $outbounds = DB::table('bookings')
            ->whereNotNull('linked_booking_id')
            ->whereNotNull('corporate_account_id')
            ->where('payment_method', 'account')
            ->get(['id', 'reference', 'linked_booking_id', 'corporate_account_id', 'meta']);

        foreach ($outbounds as $out) {
            $ret = DB::table('bookings')->where('id', $out->linked_booking_id)->first();
            if (! $ret || ! $ret->is_return_leg || $ret->payment_method !== 'card'
                || $ret->payment_status !== 'pending' || $ret->source !== 'web') {
                continue;
            }
            $meta = json_decode((string) $ret->meta, true) ?: [];
            if (! empty($meta['square_payment']) || DB::table('payments')->where('booking_id', $ret->id)->where('status', 'paid')->exists()) {
                continue; // money already taken on the card — leave it for the office
            }
            $outMeta = json_decode((string) $out->meta, true) ?: [];
            $meta = array_merge($meta, array_filter([
                'payment_preference' => 'account',
                'account_booking' => true,
                'account_code' => $outMeta['account_code'] ?? null,
                'account_name' => $outMeta['account_name'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''));

            DB::table('bookings')->where('id', $ret->id)->update([
                'corporate_account_id' => $out->corporate_account_id,
                'payment_method' => 'account',
                'meta' => json_encode($meta),
                'updated_at' => now(),
            ]);
            $fixed[] = (string) $ret->reference;
        }

        Log::info('Account return legs moved onto the account', ['refs' => $fixed]);

        return $fixed;
    }
}
