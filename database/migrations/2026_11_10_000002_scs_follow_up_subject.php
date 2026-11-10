<?php

use App\Models\Prospect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * SCS was emailed by hand on 3 Nov as "Regional Chauffeur Partnership | South
 * Yorkshire" (Sent Items), but the prospect still held the draft subject, so the
 * day-4 follow-up would have gone as "Re: Reciprocal partnership…" outside the
 * thread. Set the subject actually used. No-op in tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('prospects')) {
            return;
        }
        Prospect::where('contact_email', 'charles@scschauffeur.co.uk')->get()
            ->each(fn (Prospect $p) => $p->forceFill(['email_subject' => 'Regional Chauffeur Partnership | South Yorkshire'])->save());
    }

    public function down(): void
    {
    }
};
