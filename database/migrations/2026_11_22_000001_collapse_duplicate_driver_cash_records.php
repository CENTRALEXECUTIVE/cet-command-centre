<?php

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Database\Migrations\Migration;

/**
 * 22 Nov 2026: tapping "Record" several times added the driver's cash again each
 * time (a £95 job read £285). Collapse a booking's DRIVER cash lines with the SAME
 * amount into one, then re-check the booking's paid status. Testing-safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        Payment::where('method', 'cash')->where('status', 'paid')->whereNotNull('booking_id')
            ->get()
            ->filter(fn ($p) => ($p->meta['source'] ?? null) === 'driver')
            ->groupBy(fn ($p) => $p->booking_id.'|'.number_format((float) $p->amount, 2))
            ->filter(fn ($g) => $g->count() > 1)
            ->each(function ($group) {
                $group->sortByDesc('id')->slice(1)->each->delete();
                if ($b = Booking::find($group->first()->booking_id)) {
                    $b->load('payments');
                    $b->reconcilePaymentStatusFromTransactions();
                }
            });
    }

    public function down(): void {}
};
