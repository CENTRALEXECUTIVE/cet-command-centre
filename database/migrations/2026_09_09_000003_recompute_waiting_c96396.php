<?php

use App\Models\Booking;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;

/**
 * One-off recompute of the FROZEN waiting time on CET-C96396 (auto-applied by
 * auto-deploy's `migrate --force`).
 *
 * The waiting charge used to count from when the driver ARRIVED. Drivers arrive
 * early on purpose, so that produced a phantom charge (a 50p "1 min" on this
 * job). The rule is now: waiting counts from the SCHEDULED PICKUP TIME, so an
 * early arrival is never charged. This re-freezes CET-C96396's recorded waiting
 * using the new anchor, at the moment the passenger actually boarded — so the
 * live job shows the correct figure straight away. A no-op in tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $booking = Booking::where('reference', 'CET-C96396')->first()
            ?? Booking::where('reference', 'like', '%C96396%')->first()
            ?? Booking::where('external_reference', 'WX9KDO')->first();
        if (! $booking) {
            return;
        }

        $meta = $booking->meta ?? [];
        $waiting = $meta['waiting'] ?? null;
        if (! is_array($waiting) || ! isset($waiting['recorded_at'])) {
            return; // nothing frozen to recompute
        }

        try {
            $boardedAt = Carbon::parse($waiting['recorded_at']);
        } catch (\Throwable) {
            return;
        }

        // Recompute against the new pickup-time anchor, as at boarding time.
        $waiting['billable_minutes'] = $booking->waitingBillableMinutes($boardedAt);
        $meta['waiting'] = $waiting;
        $booking->forceFill(['meta' => $meta])->save();
    }

    public function down(): void
    {
        // No-op: a data correction is not reversible.
    }
};
