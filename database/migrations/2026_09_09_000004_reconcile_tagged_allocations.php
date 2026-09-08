<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Database\Migrations\Migration;

/**
 * One-time catch-up (auto-applied by auto-deploy's `migrate --force`): reconcile
 * every upcoming Pending/Allocated booking to the driver named on its calendar
 * title tag (ABDI / MAJ / a named driver). This corrects jobs that were sitting
 * on the wrong driver — e.g. CET-BD7F3B, tagged (ABDI) but allocated to Majid.
 *
 * Same logic the cet:auto-allocate-tagged command now runs every few minutes;
 * this just applies it once immediately on deploy so nothing waits for the next
 * tick. Only reassigns jobs not yet accepted/started, and only when the tag
 * clearly names a system driver. Never touches Google. A no-op in tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        Booking::whereIn('status', [BookingStatus::Pending->value, BookingStatus::Allocated->value])
            ->where('pickup_at', '>=', now()->subHours(12))
            ->where(function ($q) {
                $q->whereNotNull('meta->driver_tag')->orWhereHas('calendarEvent');
            })
            ->with('calendarEvent')
            ->get()
            ->each(fn (Booking $b) => $b->reconcileDriverWithCalendarTag());
    }

    public function down(): void
    {
        // No-op: an allocation correction is not reversible.
    }
};
