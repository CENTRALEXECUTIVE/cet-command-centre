<?php

use Illuminate\Database\Migrations\Migration;

/**
 * NEUTRALISED — do not run.
 *
 * This migration was written on the belief that ETO exported pickup times in
 * UTC, so it added an hour to every ETO booking. That belief was WRONG: ETO
 * exports the booking's UK LOCAL time (the same time shown on the customer email
 * and the calendar), so stored times were already correct. Running the original
 * shift would have made every correct 06:45 booking show 07:45.
 *
 * It is left as a no-op so any database that has not yet run it stays correct,
 * and the migration history is preserved. The real fix lives in the importer
 * (EtoBookingImporter::parseDate), which now parses ETO times as-is.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Intentionally does nothing — see the class docblock.
    }

    public function down(): void
    {
        // Nothing to reverse.
    }
};
