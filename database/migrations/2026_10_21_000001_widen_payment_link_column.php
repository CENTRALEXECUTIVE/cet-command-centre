<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-transaction card link is stored in `payments.tide_payment_link`, which
 * was VARCHAR(255). A live Stripe Checkout URL is 500+ characters (a long signed
 * `cs_live_…#fid…` fragment), so saving it blew up with "Data too long for column
 * 'tide_payment_link'" and the whole "create card link" action 500'd. Widen it to
 * TEXT so any provider's checkout URL fits.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payments', 'tide_payment_link')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->text('tide_payment_link')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('payments', 'tide_payment_link')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->string('tide_payment_link')->nullable()->change();
        });
    }
};
