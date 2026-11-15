<?php

use App\Models\Booking;
use App\Support\AirportMatcher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Follow-up to 2026_11_15_000001: the flight was moved onto the return leg, but the
 * outbound kept showing it because the page/email fall back to the calendar details
 * block (built when it was on the outbound). Flag those outbounds so they show no
 * flight. Calendar untouched. No-op in tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('bookings')) {
            return;
        }

        Booking::where('source', 'web')
            ->where('is_return_leg', false)
            ->whereNull('flight_number')
            ->whereNotNull('linked_booking_id')
            ->get()
            ->each(function (Booking $out) {
                $ret = Booking::find($out->linked_booking_id);
                if (! $ret || ! $ret->is_return_leg || blank($ret->flight_number)
                    || ! $ret->pickup_at || $ret->pickup_at->isPast()
                    || AirportMatcher::isAirport($out->pickup_address)
                    || ! AirportMatcher::isAirport($out->destination_address)) {
                    return;
                }
                $out->forceFill(['meta' => array_merge($out->meta ?? [], ['flight_cleared' => true])])->saveQuietly();
            });
    }

    public function down(): void
    {
    }
};
