<?php

use App\Models\Prospect;
use App\Models\User;
use App\Services\Outreach\OutreachBatch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * 12 Nov 2026 (directors, in chat: "Schedule the emails to send from 9am"):
 * 1. Keepmoat: email@keepmoat.co.uk can't be confirmed (their site uses keepmoat.com
 *    and blocks checks). Hold it (no address) so it can't bounce; find a real contact.
 * 2. Queue today's batch from 09:00 UK, 5 minutes apart, approved by Abdi. Any
 *    candidate whose domain has no mail server is skipped (no bounces). The
 *    every-minute cet:outreach-send-queued sends them and re-checks each at send
 *    time. Only on 12 Nov 2026 when nothing is sent or queued yet. No-op in tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('prospects')) {
            return;
        }

        Prospect::where('contact_email', 'email@keepmoat.co.uk')->get()->each(function (Prospect $p) {
            $p->forceFill(['contact_email' => null, 'next_action_at' => null])->save();
            $p->log('note', 'email@keepmoat.co.uk could not be confirmed (site uses keepmoat.com). On hold until a working contact is found.');
        });

        $tz = config('app.timezone', 'Europe/London');
        $now = Carbon::now($tz);
        if ($now->toDateString() !== '2026-11-12') {
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

        $at = Carbon::parse('2026-11-12 09:00', $tz);
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
                $hasMail = true; // DNS hiccup on the server: don't block the batch
            }
            if (! $hasMail) {
                $p->log('note', "Skipped from the 12 Nov batch: {$domain} has no mail server.");

                continue;
            }
            $q[] = ['id' => $p->id, 'at' => $at->copy()->utc()->toIso8601String(), 'approver' => $approver->id, 'status' => $p->status];
            $p->log('note', 'Queued for '.$at->format('H:i').' on 12 Nov (approved by '.$approver->name.' in chat).');
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
