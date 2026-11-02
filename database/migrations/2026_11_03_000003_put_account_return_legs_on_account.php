<?php

use App\Support\AccountReturnLegRepair;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * ONE-OFF data fix, no schema change: web return legs booked by an account
 * customer before the widget fix were saved as card jobs off the account. Puts
 * the unpaid ones on their outbound's account. Guarded to a no-op in tests (the
 * repair itself is tested directly).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('bookings')) {
            return;
        }
        AccountReturnLegRepair::run();
    }

    public function down(): void
    {
        // Data fix only.
    }
};
