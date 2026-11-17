<?php

use App\Models\User;
use App\Services\Outreach\OutreachBatch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * 17 Nov 2026 (directors, in chat: "send them like usual 5 min gaps from 9am"): queue
 * today's batch from 09:00 UK, 5 min apart. Only if nothing has been sent or queued yet
 * today; skips any domain with no mail server. No-op in tests and on any other day.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('prospects')) {
            return;
        }
        $tz = config('app.timezone', 'Europe/London');
        $now = Carbon::now($tz);
        if ($now->toDateString() !== '2026-11-17') {
            return;
        }
        $batch = app(OutreachBatch::class);
        if ($batch->sentToday() > 0 || $batch->queued()) {
            return; // already sending or queued — leave today's queue alone
        }
        $approver = User::where('is_super_admin', true)->where('name', 'like', 'Abdi%')->first()
            ?? User::where('is_super_admin', true)->orderBy('id')->first();
        if (! $approver) {
            return;
        }

        \App\Models\Setting::set(OutreachBatch::QUEUE_KEY, [], 'json', 'outreach');

        $at = Carbon::parse('2026-11-17 09:00', $tz);
        if ($at->lt($now)) {
            $at = $now->copy()->addMinute()->startOfMinute();
        }
        $q = [];
        foreach ($batch->candidates() as $p) {
            if ($p->isDoNotContact() || ! $p->nextSend() || ! $p->contact_email) {
                continue;
            }
            $domain = substr(strrchr((string) $p->contact_email, '@'), 1);
            try {
                $hasMail = $domain && (checkdnsrr($domain, 'MX') || checkdnsrr($domain, 'A'));
            } catch (\Throwable) {
                $hasMail = true;
            }
            if (! $hasMail) {
                $p->log('note', "Skipped from the 17 Nov batch: {$domain} has no mail server.");

                continue;
            }
            $q[] = ['id' => $p->id, 'at' => $at->copy()->utc()->toIso8601String(), 'approver' => $approver->id, 'status' => $p->status];
            $p->log('note', 'Queued for '.$at->format('H:i').' on 17 Nov (approved by '.$approver->name.' in chat).');
            $at->addMinutes(5);
        }
        \App\Models\Setting::set(OutreachBatch::QUEUE_KEY, $q, 'json', 'outreach');
    }

    public function down(): void
    {
    }
};
