<?php

use App\Models\Prospect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Directors (9 Nov 2026): take ITM Power out of outreach. The company is in
 * financial difficulty and won't change suppliers. Marked "Not interested" (not
 * do-not-contact, so it can be revisited later); no emails or follow-ups. Only
 * moves it if it's still open. No-op in tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('prospects')) {
            return;
        }
        Prospect::where('company', 'ITM Power')
            ->whereNotIn('status', Prospect::CLOSED)->get()
            ->each(function (Prospect $p) {
                $from = $p->statusLabel();
                $p->status = 'not_interested';
                $p->next_action_at = null;
                $p->save();
                $p->log('status', $from.' → Not interested · Directors 9 Nov 2026: in financial difficulty, won\'t move supplier. Removed from outreach.');
            });
    }

    public function down(): void
    {
        // Directors' decision; reopen by hand on the prospect page if needed.
    }
};
