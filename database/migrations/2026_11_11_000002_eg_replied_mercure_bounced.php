<?php

use App\Models\Prospect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Inbox on 11 Nov 2026:
 * - EG Chauffeurs replied (asked about the fleet); the directors answered by hand.
 *   Mark Replied so no automatic follow-up goes on Mon 16 Nov.
 * - Mercure Sheffield St Paul's: H6628@accor.com does not exist (bounced). Hold (no
 *   address, no follow-up) until a working contact is found.
 * No-op in tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('prospects')) {
            return;
        }

        Prospect::where('contact_email', 'info@egchauffeurs.com')->get()->each(function (Prospect $p) {
            $p->forceFill(['status' => 'replied', 'next_action_at' => null])->save();
            $p->log('note', 'Replied 11 Nov (asked whether S-Class / i7 / V-Class are our own). Directors answered by hand: own fleet + trusted licensed chauffeurs, 4 V-Class, E-Class.');
        });

        Prospect::where('contact_email', 'H6628@accor.com')->get()->each(function (Prospect $p) {
            $p->forceFill(['contact_email' => null, 'status' => 'new', 'next_action_at' => null])->save();
            $p->log('note', 'H6628@accor.com does not exist (bounced 11 Nov). On hold until a working contact is found.');
        });
    }

    public function down(): void
    {
    }
};
