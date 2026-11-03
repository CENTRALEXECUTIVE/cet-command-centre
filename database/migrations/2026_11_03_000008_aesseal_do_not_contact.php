<?php

use App\Models\Prospect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * AESSEAL plc replied (3 Nov 2026): not interested, remove from the mailing list.
 * Marks them do-not-contact (permanent; no further emails or follow-ups).
 * AESSEAL New York Stadium (Rotherham United's venue) is a different organisation
 * and is not touched. No-op in tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('prospects')) {
            return;
        }
        Prospect::where('company', 'AESSEAL plc')->where('status', '!=', 'do_not_contact')->get()
            ->each(function (Prospect $p) {
                $from = $p->statusLabel();
                $p->status = 'do_not_contact';
                $p->replied_at ??= now();
                $p->next_action_at = null;
                $p->save();
                $p->log('status', $from.' → Do not contact · Replied 3 Nov 2026: not interested, asked to be removed from the mailing list');
            });
    }

    public function down(): void
    {
        // Opt-outs are permanent.
    }
};
