<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * Number changeover scoped to NAMED customers only. Nigel Corfield, Clare Bell
 * and James McDermott already have the old number (+447700159929) in their
 * driver-details message, so their jobs keep it; every other job uses the new
 * number (+447575583899). Replaces the earlier date-cutover rule. Clear the
 * names setting once those jobs have run, then the old number can be released.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        Setting::set('twilio_customer_line', '+447575583899', 'string', 'telephony');
        Setting::set('twilio_customer_line_prev', '+447700159929', 'string', 'telephony');
        Setting::set('twilio_customer_line_prev_names', 'Nigel Corfield, Clare Bell, James McDermott', 'string', 'telephony');
        // Retire the old date-based rule.
        Setting::set('twilio_customer_line_cutover', '', 'string', 'telephony');
    }

    public function down(): void
    {
        // Non-destructive.
    }
};
