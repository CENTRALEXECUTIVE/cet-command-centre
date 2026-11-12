<?php

use App\Models\Prospect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * 12 Nov 2026: ITP Aero's mail server refused our 09:40 email to
 * clare.hitchings@itpaero.com ("not authorized to relay" — that mailbox only accepts
 * approved senders). Hold it (no address, no follow-up) until a public contact is
 * found, so the Monday follow-up doesn't bounce too. No-op in tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('prospects')) {
            return;
        }

        Prospect::where('contact_email', 'clare.hitchings@itpaero.com')->get()->each(function (Prospect $p) {
            $p->forceFill(['contact_email' => null, 'next_action_at' => null])->save();
            $p->log('note', 'clare.hitchings@itpaero.com rejected our email on 12 Nov (sender not authorised). On hold until a public contact is found.');
        });
    }

    public function down(): void
    {
    }
};
