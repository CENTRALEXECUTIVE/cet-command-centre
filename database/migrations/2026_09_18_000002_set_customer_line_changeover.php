<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * Number changeover: the new customer line is +447575583899, but jobs up to and
 * including 2026-09-19 (tomorrow's already-notified bookings) keep the OLD number
 * +447700159929 — their customers already have it. From 2026-09-20 every job uses
 * the new number, so the old one can then be released. The cutover settings go
 * inert once the date has passed. Stored in-app (no .env edit).
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
        Setting::set('twilio_customer_line_cutover', '2026-09-19', 'string', 'telephony');
    }

    public function down(): void
    {
        // Non-destructive.
    }
};
