<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * Switch the customer masking line to the new Twilio number (+447575583899),
 * replacing the old +447700159929. Stored as an in-app Setting so it overrides
 * the server's env value without a .env edit. Runs on deploy (migrate --force).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Never touch settings during the test run.
        if (app()->environment('testing')) {
            return;
        }

        Setting::set('twilio_customer_line', '+447575583899', 'string', 'telephony');
    }

    public function down(): void
    {
        // Non-destructive: leave the number as-is on rollback.
    }
};
