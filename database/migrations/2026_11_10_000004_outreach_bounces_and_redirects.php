<?php

use App\Models\Prospect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Bounces / redirects seen in the inbox on 10 Nov 2026:
 * - B. Braun: bbraun.co.uk has no mail server (no MX), so customercare.bbmuk@bbraun.co.uk
 *   can never arrive. Their real addresses are @bbraun.com: use info.bbmuk@bbraun.com and
 *   send the first email again.
 * - Unipart Rail: enquiries@ is closed; auto-reply says use cs@unipartrail.com. Send again.
 * - Irwin Mitchell: enquiries@irwinmitchell.com does not exist. Hold (no address) until a
 *   working contact is found.
 * - Vulcan Seals: Jane is on leave until Mon 16 Nov; follow-up on Wed 18 Nov.
 * No-op in tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('prospects')) {
            return;
        }

        $resend = [
            'customercare.bbmuk@bbraun.co.uk' => ['info.bbmuk@bbraun.com', 'bbraun.co.uk has no mail server; the 9 Nov email bounced. Re-sending to info.bbmuk@bbraun.com.'],
            'enquiries@unipartrail.com' => ['cs@unipartrail.com', 'enquiries@ is closed (auto-reply 10 Nov); re-sending to cs@unipartrail.com.'],
        ];
        foreach ($resend as $old => [$new, $note]) {
            Prospect::where('contact_email', $old)->get()->each(function (Prospect $p) use ($new, $note) {
                $p->forceFill(['contact_email' => $new, 'status' => 'new', 'next_action_at' => null, 'last_contacted_at' => null])->save();
                $p->log('note', $note);
            });
        }

        Prospect::where('contact_email', 'enquiries@irwinmitchell.com')->get()->each(function (Prospect $p) {
            $p->forceFill(['contact_email' => null, 'status' => 'new', 'next_action_at' => null])->save();
            $p->log('note', 'enquiries@irwinmitchell.com does not exist (bounced 10 Nov). On hold until a working contact is found.');
        });

        Prospect::where('contact_email', 'jane.mcguinness@vulcanseals.com')->where('status', 'emailed')->get()
            ->each(function (Prospect $p) {
                $p->forceFill(['next_action_at' => '2026-11-18'])->save();
                $p->log('note', 'Jane on annual leave until Mon 16 Nov (auto-reply). Follow-up moved to Wed 18 Nov.');
            });
    }

    public function down(): void
    {
    }
};
