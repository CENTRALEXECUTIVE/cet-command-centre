<?php

use App\Models\Setting;
use App\Models\User;
use App\Services\Outreach\OutreachBatch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * 18 Nov 2026: today's batch skipped 4 good addresses (University of Sheffield, DocFest,
 * SCS, Wake Smith) because the host's DNS wrongly said "no mail server". Add the day's
 * remaining candidates to the END of today's queue, 5 min apart. Only today; no-op in tests.
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
        if ($now->toDateString() !== '2026-11-18') {
            return;
        }
        $batch = app(OutreachBatch::class);
        $approver = User::where('is_super_admin', true)->where('name', 'like', 'Abdi%')->first()
            ?? User::where('is_super_admin', true)->orderBy('id')->first();
        if (! $approver) {
            return;
        }
        $q = $batch->queued();
        $last = collect($q)->map(fn ($e) => Carbon::parse($e['at']))->max();
        $at = ($last && $last->gt($now) ? $last->copy()->addMinutes(5) : $now->copy()->addMinute()->startOfMinute())->timezone($tz);
        $inQueue = collect($q)->pluck('id')->all();

        foreach ($batch->candidates() as $p) {
            if (in_array($p->id, $inQueue, true) || $p->isDoNotContact() || ! $p->nextSend() || ! $p->contact_email) {
                continue;
            }
            $q[] = ['id' => $p->id, 'at' => $at->copy()->utc()->toIso8601String(), 'approver' => $approver->id, 'status' => $p->status];
            $p->log('note', 'Queued for '.$at->format('H:i').' on 18 Nov (re-added: the DNS check had wrongly skipped it).');
            $at->addMinutes(5);
        }
        Setting::set(OutreachBatch::QUEUE_KEY, array_values($q), 'json', 'outreach');
    }

    public function down(): void
    {
    }
};
