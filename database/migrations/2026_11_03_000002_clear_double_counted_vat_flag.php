<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ONE-OFF data fix, no schema change. The Square webhook used to tick "VAT
 * received" on any card payment, so the VAT was counted twice once the job was
 * re-priced. CET-007A2C (Kidderminster, £180 + VAT = £216, £151.20 paid) read
 * £28.80 due instead of £64.80. Clears that stale tick on this booking only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('bookings')) {
            return;
        }

        $rows = DB::table('bookings')->where('reference', 'CET-007A2C')
            ->orWhere('external_reference', 'CET-007A2C')->get(['id', 'meta']);
        foreach ($rows as $row) {
            $meta = json_decode((string) $row->meta, true) ?: [];
            if (! empty($meta['vat_paid'])) {
                unset($meta['vat_paid']);
                DB::table('bookings')->where('id', $row->id)->update(['meta' => json_encode($meta)]);
            }
        }
    }

    public function down(): void
    {
        // Data fix only.
    }
};
