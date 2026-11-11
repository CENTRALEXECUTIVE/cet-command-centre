<?php

use App\Models\Prospect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * EG Chauffeurs replied on 11 Nov 12:01. The earlier fix set status Replied but not
 * replied_at, so the dashboard's Replies / reply rate didn't count it. No-op in tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('prospects')) {
            return;
        }

        Prospect::where('contact_email', 'info@egchauffeurs.com')->whereNull('replied_at')->get()
            ->each(fn (Prospect $p) => $p->forceFill(['replied_at' => Carbon::parse('2026-11-11 12:01', 'Europe/London')])->save());
    }

    public function down(): void
    {
    }
};
