<?php

use App\Models\Prospect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Found in the inbox on 10 Nov 2026 before approving the batch:
 * - First Travel Solutions (FirstGroup) replied on 3 Nov with their supplier
 *   registration link, so no follow-up: mark Replied.
 * - Vulcan Seals is an existing customer (Jane McGuinness, PA to the MD, booked in
 *   October). Their first email goes to Jane personally (directors' wording, 10 Nov)
 *   instead of the cold note to contact@, in today's batch.
 * - William Cook Cast Products: add that we recently looked after a visitor to their
 *   Parkway Avenue site (no customer named: GDPR).
 * Only touches prospects still at New (or in the cold sequence for First Travel). No-op in tests.
 */
return new class extends Migration
{
    private const JANE_BODY = <<<'TXT'
Hi Jane,

I hope you're well. A quick note to let you know what we can now offer Vulcan Seals.

Central Executive Transfers Ltd is now VAT registered, so every journey comes with a proper VAT invoice your finance team can reclaim.

We cover all your company travel, not just airport runs:

- Transfers to and from every UK airport, with flight tracking so delays are covered
- Business travel anywhere in the UK, including client meetings, site visits and trips to other offices
- A local chauffeur by the hour for visitors, roadshows or a busy day of meetings
- Work dos, Christmas parties and team events, with V-Class and minibuses for groups
- Collecting visiting customers and suppliers from the station or hotel

And to make it simple for you:

- A business account with one monthly VAT invoice, with your PO or cost-centre reference on each journey
- Or pay by card at booking, whichever suits you
- Driver details sent to you and the traveller before pickup
- Phone answered 24/7 if plans change

Whenever you next need a car, just reply to this email or give us a call and we'll take care of it.

Kind regards,
TXT;

    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('prospects')) {
            return;
        }

        Prospect::where('contact_email', 'travel.solutions@firstbus.co.uk')
            ->whereIn('status', ['new', 'emailed', 'follow_up_1', 'follow_up_2'])->get()
            ->each(function (Prospect $p) {
                $from = $p->statusLabel();
                $p->forceFill(['status' => 'replied', 'replied_at' => $p->replied_at ?? Carbon::parse('2026-11-03 13:17'), 'next_action_at' => null])->save();
                $p->log('status', $from.' → Replied · 3 Nov 2026: FTS Compliance (fts.compliance@firsttravelsolutions.com) sent the supplier registration link, supplier.fts-tech.co.uk/register (desktop). Next: complete the registration.');
            });

        Prospect::where('company', 'like', 'Vulcan Seals%')->where('status', 'new')->get()
            ->each(function (Prospect $p) {
                $p->forceFill([
                    'contact_name' => 'Jane McGuinness', 'contact_email' => 'jane.mcguinness@vulcanseals.com',
                    'contact_role' => 'PA to Managing Director', 'cc_email' => null, 'cc_name' => null,
                    'email_subject' => 'Business travel account for Vulcan Seals',
                    'email_body' => str_replace("\r\n", "\n", self::JANE_BODY),
                ])->save();
                $p->log('note', 'Existing customer (Jane booked in October 2026). First email now goes to Jane personally with the directors\' wording (10 Nov), not the cold note to contact@.');
            });

        $line = "We recently looked after a visitor to your Parkway Avenue site, who was very pleased with the service.\n\n";
        Prospect::where('company', 'like', 'William Cook Cast Products%')->where('status', 'new')->get()
            ->each(function (Prospect $p) use ($line) {
                $body = str_replace("\r\n", "\n", (string) $p->email_body);
                if (str_contains($body, 'Parkway Avenue site, who was very pleased')) {
                    return;
                }
                $anchor = "transport for visiting customers.\n\n";
                $new = str_contains($body, $anchor)
                    ? str_replace($anchor, $anchor.$line, $body)
                    : preg_replace('/\n\n(Our corporate service includes:)/', "\n\n".$line.'$1', $body, 1);
                if ($new && $new !== $body) {
                    $p->forceFill(['email_body' => $new])->save();
                    $p->log('note', 'First email now mentions a recent visitor to Parkway Avenue we looked after (10 Nov).');
                }
            });
    }

    public function down(): void
    {
    }
};
