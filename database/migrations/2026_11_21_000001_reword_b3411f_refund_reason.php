<?php

use App\Models\Booking;
use Illuminate\Database\Migrations\Migration;

/**
 * Directors, 21 Nov 2026: the £72 refund on CET-B3411F was typed as "downgraded" —
 * reword it to "Vehicle changed to Executive" (shown on the customer's updated
 * confirmation). Square keeps its own copy; this only changes our record. Testing-safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $b = Booking::where('reference', 'CET-B3411F')->first();
        if (! $b) {
            return;
        }
        $meta = $b->meta ?? [];
        $changed = false;
        foreach ((array) ($meta['refunds'] ?? []) as $i => $r) {
            if (is_array($r)) {
                $meta['refunds'][$i]['reason'] = 'Vehicle changed to Executive';
                $changed = true;
            }
        }
        if ($changed) {
            $b->forceFill(['meta' => $meta])->save();
        }
    }

    public function down(): void
    {
        // Non-destructive.
    }
};
