<?php

use App\Models\Booking;
use App\Support\AirportMatcher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * 15 Nov 2026: on a web return to the airport (home → airport, airport → home) the form
 * asks for the RETURN flight, but it was saved on the outbound drop-off and the return
 * leg (the airport pickup the driver meets) had none — e.g. CET-F3979B. Move the flight
 * number and landing time onto the return leg for every UPCOMING such trip. Only when
 * the return has no flight of its own. Calendar untouched. No-op in tests.
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
            ->whereNotNull('flight_number')
            ->whereNotNull('linked_booking_id')
            ->get()
            ->each(function (Booking $out) {
                $ret = Booking::find($out->linked_booking_id);
                if (! $ret || ! $ret->is_return_leg || filled($ret->flight_number)
                    || ! $ret->pickup_at || $ret->pickup_at->isPast()
                    || AirportMatcher::isAirport($out->pickup_address)
                    || ! AirportMatcher::isAirport($out->destination_address)) {
                    return;
                }
                $landing = $out->meta['flight_landing_at'] ?? null;

                $retMeta = $ret->meta ?? [];
                if ($landing) {
                    $retMeta['flight_landing_at'] = $landing;
                }
                $ret->forceFill(['flight_number' => $out->flight_number, 'meta' => $retMeta])->saveQuietly();

                $outMeta = $out->meta ?? [];
                unset($outMeta['flight_landing_at']);
                $out->forceFill(['flight_number' => null, 'meta' => $outMeta])->saveQuietly();
            });
    }

    public function down(): void
    {
    }
};
