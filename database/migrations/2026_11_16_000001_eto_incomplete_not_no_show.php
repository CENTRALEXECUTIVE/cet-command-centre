<?php

use App\Models\Booking;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 16 Nov 2026: the ETO import mapped "Incomplete" (an unpaid, abandoned checkout) to
 * No Show, so e.g. Justin Hall's unpaid ASBTVGa sat beside his real paid booking as a
 * "No Show" job. Cancel every No Show that no person ever marked (no status-history
 * row to no_show) and that isn't paid — those can only have come from the import.
 * Quiet: no emails, calendar untouched. No-op in tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('bookings')) {
            return;
        }

        $markedByPerson = DB::table('booking_status_histories')->where('to_status', 'no_show')->pluck('booking_id')->all();

        Booking::where('status', 'no_show')
            ->whereNotIn('id', $markedByPerson)
            ->where(fn ($q) => $q->whereNull('payment_status')->orWhere('payment_status', '!=', 'paid'))
            ->get()
            ->each(function (Booking $b) {
                $meta = $b->meta ?? [];
                $meta['eto_incomplete'] = true;
                $meta['cancel_reason'] = 'ETO "Incomplete" — the customer never finished paying (abandoned checkout). Was imported as No Show by mistake.';
                $b->forceFill(['status' => 'cancelled', 'meta' => $meta])->saveQuietly();
            });
    }

    public function down(): void
    {
    }
};
