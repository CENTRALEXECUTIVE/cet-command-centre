<?php

use App\Mail\OutreachMail;
use App\Models\Prospect;
use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * 16 Nov 2026 (directors, in chat: wording approved and "send"): reply to Fabiola Fofana,
 * SIXT ride Country Manager UK/IE, who asked whether we're VAT registered. Sent once,
 * in the outreach house style + signature, threaded under her email. Marks the SIXT
 * prospect as replied. No-op in tests; never sends twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || Setting::get('sixt_reply_16nov_sent')) {
            return;
        }

        $body = "Dear Fabiola,\n\n"
            ."Thank you for coming back to us. Yes, we are VAT registered:\n\n"
            ."• Company: Central Executive Transfers Ltd\n"
            ."• VAT number: GB527220417\n"
            ."• Company number: 15749931\n"
            ."• Sheffield City Council operator licence\n\n"
            ."Kind regards,";

        try {
            Mail::to('fabiola.fofana@sixt.com')->send(new OutreachMail(
                'RE: SIXT partnership', $body,
                '<DB9PR05MB79621204240B2F2570BEA6C6E27C2@DB9PR05MB7962.eurprd05.prod.outlook.com>',
            ));
            Setting::set('sixt_reply_16nov_sent', now()->toIso8601String(), 'string', 'outreach');
        } catch (\Throwable $e) {
            Log::warning('[outreach] SIXT reply failed: '.$e->getMessage());

            return;
        }

        if (Schema::hasTable('prospects')) {
            Prospect::where('company', 'like', '%Sixt%')->get()->each(function (Prospect $p) {
                $from = $p->statusLabel();
                if (! in_array($p->status, ['replied', 'won', 'do_not_contact', 'not_interested'], true)) {
                    $p->status = 'replied';
                    $p->replied_at ??= \Illuminate\Support\Carbon::parse('2026-11-16 19:51');
                    $p->next_action_at = null;
                    $p->save();
                }
                $p->log('note', $from.' → Replied · 16 Nov 2026: Fabiola Fofana (Country Manager UKI/IE, fabiola.fofana@sixt.com) — keen to onboard, asked if VAT registered. Replied same evening: Ltd, VAT GB527220417, company 15749931, SCC operator licence.');
            });
        }
    }

    public function down(): void
    {
    }
};
