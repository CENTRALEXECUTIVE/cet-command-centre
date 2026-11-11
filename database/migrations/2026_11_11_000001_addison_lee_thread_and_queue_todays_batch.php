<?php

use App\Models\Prospect;
use App\Models\User;
use App\Services\Outreach\OutreachBatch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * 11 Nov 2026 (directors, in chat: "Do it yourself and deploy. Prepare the send…
 * start sending for 9am"):
 * 1. Addison Lee: the 5 Nov email went to partnerships@addisonlee.com as
 *    "Regional Chauffeur Partnership Enquiry | Sheffield & South Yorkshire", so the
 *    day-4 follow-up must go there under that subject (not sales@), to thread.
 * 2. Queue today's batch (the 10 the batch page shows) from 09:00 UK, 5 minutes
 *    apart, approved by Abdi. The every-minute cet:outreach-send-queued sends them and
 *    re-checks each one at send time. Only runs on 11 Nov 2026, when nothing has been
 *    sent or queued today. No-op in tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('prospects')) {
            return;
        }

        Prospect::where('company', 'like', 'Addison Lee%')->whereIn('status', ['emailed', 'follow_up_1'])->get()
            ->each(function (Prospect $p) {
                $p->forceFill([
                    'contact_email' => 'partnerships@addisonlee.com',
                    'email_subject' => 'Regional Chauffeur Partnership Enquiry | Sheffield & South Yorkshire',
                ])->save();
                $p->log('note', 'Follow-up address/subject set to the 5 Nov email (partnerships@, original subject) so it threads. Their only reply was the bookings-desk auto ticket #2986589.');
            });

        $tz = config('app.timezone', 'Europe/London');
        $now = Carbon::now($tz);
        if ($now->toDateString() !== '2026-11-11') {
            return;
        }
        $batch = app(OutreachBatch::class);
        if ($batch->sentToday() > 0 || count($batch->queued()) > 0) {
            return;
        }
        $approver = User::where('is_super_admin', true)->where('name', 'like', 'Abdi%')->first()
            ?? User::where('is_super_admin', true)->orderBy('id')->first();
        if (! $approver) {
            return;
        }

        $at = Carbon::parse('2026-11-11 09:00', $tz);
        if ($at->lt($now)) {
            $at = $now->copy()->addMinute()->startOfMinute();
        }
        $q = [];
        foreach ($batch->candidates() as $p) {
            if ($p->isDoNotContact() || ! $p->nextSend()) {
                continue;
            }
            $q[] = ['id' => $p->id, 'at' => $at->copy()->utc()->toIso8601String(), 'approver' => $approver->id, 'status' => $p->status];
            $p->log('note', 'Queued for '.$at->format('H:i').' on 11 Nov (approved by '.$approver->name.' in chat).');
            $at->addMinutes(5);
        }
        if ($q) {
            \App\Models\Setting::set(OutreachBatch::QUEUE_KEY, $q, 'json', 'outreach');
        }
    }

    public function down(): void
    {
    }
};
