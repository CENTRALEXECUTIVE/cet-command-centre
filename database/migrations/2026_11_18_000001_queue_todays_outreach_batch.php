<?php

use App\Services\Outreach\OutreachBatch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * 18 Nov 2026 (directors, in chat: "send them like usual 5 min gaps"): queue today's
 * batch from now, 5 min apart. From tomorrow cet:outreach-auto-queue does this daily
 * at 09:00. No-op in tests, on other days, or if anything is already sent/queued.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('prospects')) {
            return;
        }
        $tz = config('app.timezone', 'Europe/London');
        if (Carbon::now($tz)->toDateString() !== '2026-11-18') {
            return;
        }
        app(OutreachBatch::class)->autoQueueToday();
    }

    public function down(): void
    {
    }
};
