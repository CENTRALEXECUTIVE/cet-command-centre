<?php

use App\Models\Prospect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * TBR Global Chauffeuring replied on 5 Nov 2026 (Barrie Bradley, supplier
 * management) and the directors submitted TBR's partner application the same
 * day. Mark the prospect Replied so the outreach batch never cold-emails them.
 * Only moves it if it's still at New / Emailed. No-op in tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('prospects')) {
            return;
        }
        Prospect::where('company', 'like', 'TBR Global Chauffeuring%')
            ->whereIn('status', ['new', 'emailed', 'follow_up_1', 'follow_up_2'])->get()
            ->each(function (Prospect $p) {
                $from = $p->statusLabel();
                $p->status = 'replied';
                $p->replied_at ??= \Illuminate\Support\Carbon::parse('2026-11-05');
                $p->next_action_at = null;
                $p->save();
                $p->log('status', $from.' → Replied · 5 Nov 2026: TBR pointed us to their partner form; application submitted the same day. Wait for their vetting contact.');
            });
    }

    public function down(): void
    {
    }
};
