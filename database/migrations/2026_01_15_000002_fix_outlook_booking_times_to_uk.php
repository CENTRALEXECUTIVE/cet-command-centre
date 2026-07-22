<?php

use Illuminate\Database\Migrations\Migration;

/**
 * NEUTRALISED — do not run. Companion to 000001.
 *
 * Same wrong assumption (that the Outlook/email feed stored UTC): it did not —
 * those times were already UK-local. Adding an hour would have pushed every
 * correct pickup forward by one hour. Left as a no-op so databases that have
 * not run it stay correct and the history is preserved.
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
