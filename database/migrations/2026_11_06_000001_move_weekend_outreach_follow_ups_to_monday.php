<?php

use App\Models\Prospect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * ONE-OFF data fix (no schema change): follow-ups already scheduled for a
 * Saturday or Sunday move to the following Monday, matching the new rule in
 * OutreachController::markEmailed (directors, Nov 2026). Closed prospects and
 * dates in the past are left alone. Nothing is sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('prospects')) {
            return;
        }

        Prospect::query()
            ->whereNotNull('next_action_at')
            ->whereNotIn('status', Prospect::CLOSED)
            ->whereDate('next_action_at', '>=', Carbon::today()->toDateString())
            ->get()
            ->each(function (Prospect $p) {
                $moved = Prospect::workingDay($p->next_action_at);
                if (! $moved->isSameDay($p->next_action_at)) {
                    $p->forceFill(['next_action_at' => $moved->toDateString()])->saveQuietly();
                }
            });
    }

    public function down(): void
    {
        // Data fix only.
    }
};
