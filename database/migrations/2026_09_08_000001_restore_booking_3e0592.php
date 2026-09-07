<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\VehicleType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;

/**
 * ONE-OFF data repair, applied automatically by auto-deploy's `migrate --force`
 * so the office doesn't have to run a command. Booking CET-3E0592 (a covering job
 * for "Lawrence", ref Ryanhn) was polluted by the old calendar-refresh bug — it
 * took on Penny Coates' 30 Aug details and its calendar event was linked to
 * Penny's Google event. This restores Lawrence's details, points it at a Lawrence
 * customer (never renaming Penny's shared record) and drops the poisoned calendar
 * link. Only ever touches CET-3E0592; Penny's real booking (CET-09A10D) and her
 * Google event are untouched. Idempotent + guarded to a no-op in tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $booking = Booking::where('reference', 'CET-3E0592')->first()
            ?? Booking::where('reference', 'like', '%3E0592%')->first();
        if (! $booking) {
            return; // not this environment, or already gone
        }

        // Already restored? nothing to do.
        if (($booking->external_reference ?? '') === 'Ryanhn'
            && str_contains((string) ($booking->meta['lead_name'] ?? ''), 'Lawrence')) {
            return;
        }

        $estate = VehicleType::where('slug', 'estate')->first()
            ?? VehicleType::where('name', 'like', '%Estate%')->first();

        $lawrence = Customer::where('phone', '07868882217')->where('name', 'like', '%Lawrence%')->first()
            ?? Customer::create(['name' => 'Lawrence', 'phone' => '07868882217']);

        $booking->forceFill([
            'external_reference' => 'Ryanhn',
            'customer_id' => $lawrence->id,
            'vehicle_type_id' => $estate?->id ?? $booking->vehicle_type_id,
            'pickup_at' => Carbon::createFromFormat('Y-m-d H:i', '2026-09-23 15:05', config('app.timezone')),
            'pickup_address' => 'Manchester Airport',
            'destination_address' => '19 Horsewood Road S13 9WL',
            'flight_number' => 'LS1754',
            'passengers' => 2,
            'luggage' => 2,
            'status' => BookingStatus::Pending->value,
            'driver_id' => null,
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'quoted_price' => null,
            'final_price' => null,
            'special_requests' => null,
            'meta' => [
                'lead_name' => 'Lawrence',
                'where' => 'MAN',
                'contact_no' => '07868882217',
                'suitcases' => 2,
                'hand_luggage' => 0,
                'driver_tag' => 'COVER',
                'source_note' => 'Covering job for another driver (ref Ryanhn). Restored from data mix.',
            ],
        ])->save();

        // Drop the poisoned calendar link (points at Penny's Google event).
        $booking->calendarEvents()->delete();

        // Rebuild a fresh event carrying the Ryanhn reference — best-effort, never
        // fatal to the repair.
        try {
            app(\App\Services\CalendarEventBuilder::class)
                ->buildFor($booking->fresh(['customer', 'vehicleType', 'airport', 'driver']));
        } catch (\Throwable) {
            // The booking is fixed regardless; the event can be rebuilt on next
            // sync or by opening + saving the booking.
        }
    }

    public function down(): void
    {
        // No-op: a data correction is not reversible.
    }
};
