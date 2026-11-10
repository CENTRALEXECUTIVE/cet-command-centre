<?php

use App\Models\Prospect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/** Jane's email closes with "just reply to this email" only (directors, 10 Nov). No-op in tests. */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('prospects')) {
            return;
        }
        Prospect::where('contact_email', 'jane.mcguinness@vulcanseals.com')->where('status', 'new')->get()
            ->each(function (Prospect $p) {
                $p->forceFill(['email_body' => str_replace(
                    "just reply to this email or give us a call and we'll take care of it.",
                    "just reply to this email and we'll take care of it.",
                    (string) $p->email_body
                )])->save();
            });
    }

    public function down(): void
    {
    }
};
