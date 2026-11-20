<?php

use App\Models\Booking;
use Illuminate\Database\Migrations\Migration;

/**
 * One-off (directors, 20 Nov 2026): CET-B3411F was downgraded V-Class (£175) →
 * Executive (£115) through Edit, which didn't yet carry the change into driver
 * pay — it kept the V-Class £157.50. Recompute the AUTO pay from the old fare.
 * Leaves it alone if pay was hand-set or anything's been paid to the driver.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }
        $b = Booking::where('reference', 'CET-B3411F')->first();
        if ($b && abs((float) $b->driverPay() - 157.50) < 0.01) {
            $b->recomputeAutoDriverPay(175.00);
        }
    }

    public function down(): void {}
};
