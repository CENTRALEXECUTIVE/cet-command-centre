<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-off cleanup (directors, 13 Nov 2026): during a Microsoft outage a web customer's
 * repeated "Book & pay" clicks created six identical return trips. Only CET-D95DCA/B was
 * paid (and is kept). The five unpaid duplicates are DELETED here (directors: "don't cancel,
 * delete them") the same way the booking page's Delete does it — into the Trash, restorable —
 * but as a direct table update, so no model events fire: no customer email, no calendar
 * change, no push.
 *
 * Safety: only touches these exact refs, only if still web / pending / unpaid, and only
 * if they belong to the same customer as the kept, paid trip.
 */
return new class extends Migration
{
    private const KEEP = 'CET-D95DCA';

    private const BASES = ['EE0C1', '88CD4', '63C34', '95EB6', '9FBDC'];

    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $keep = DB::table('bookings')->where('reference', self::KEEP)->first();
        if (! $keep || ! $keep->customer_id) {
            return;
        }

        $refs = [];
        foreach (self::BASES as $base) {
            $refs[] = 'CET-'.$base.'A';
            $refs[] = 'CET-'.$base.'B';
        }

        $rows = DB::table('bookings')
            ->whereIn('reference', $refs)
            ->where('customer_id', $keep->customer_id)
            ->where('source', 'web')
            ->where('status', 'pending')
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('payment_status')->orWhere('payment_status', '!=', 'paid'))
            ->get(['id', 'reference', 'meta']);

        foreach ($rows as $row) {
            $meta = json_decode((string) $row->meta, true) ?: [];
            $meta['duplicate_of'] = self::KEEP;
            $meta['deleted_reason'] = 'Duplicate web submission (13 Nov 2026 email outage) — the paid trip is '.self::KEEP.'. Customer not emailed.';

            DB::table('bookings')->where('id', $row->id)->update([
                'meta' => json_encode($meta),
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Not reversed automatically; restore from Bookings → Trash if ever needed.
    }
};
