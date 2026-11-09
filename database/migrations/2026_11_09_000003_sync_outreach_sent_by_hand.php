<?php

use App\Models\Prospect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * The directors sent first outreach emails by hand from Outlook on 3, 5 and 6 Nov
 * 2026 (checked in the admin@ Sent Items on 9 Nov). Record them on the prospects so
 * the approved daily batch never sends a SECOND first email, and their day-4
 * follow-ups come due on the right weekday. Replies seen in the inbox are recorded
 * too (Blacklane, CMS replied; Boeing not interested).
 *
 * Only touches a prospect still at "New" (or New/Emailed for a reply), so anything
 * the office already updated by hand is left alone. A missing contact email is
 * filled with the address actually used; an existing one is never overwritten.
 * Matched by email, else by exact company name. No-op in tests.
 */
return new class extends Migration
{
    private const SENT = [
        // [company, address used, date sent, outcome, note]
        ['Flint Bishop (Sheffield office)', 'enquiries@flintbishop.co.uk', '2026-11-03', 'emailed', null],
        ['First Travel Solutions (FirstGroup)', 'travel.solutions@firstbus.co.uk', '2026-11-03', 'emailed', null],
        ['CMAC Group', 'supplier.relations@cmacgroup.co.uk', '2026-11-03', 'emailed', null],
        ['Birchwood & Co Case Management Ltd', 'info@birchwoodandcompany.com', '2026-11-03', 'emailed', null],
        ['SCS (Specialist Chauffeur Services)', 'charles@scschauffeur.co.uk', '2026-11-03', 'emailed', null],
        ['Rolls-Royce (Advanced Blade Casting Facility)', 'UK_enquiries@rolls-royce.com', '2026-11-03', 'emailed', null],
        ['University of Sheffield (Corporate Events team)', 'corpevents@sheffield.ac.uk', '2026-11-05', 'emailed', null],
        ['Sheffield Forgemasters', 'sales@sfel.com', '2026-11-05', 'emailed', null],
        ['Carey International (Alliance Partner Network)', 'sales@carey.com', '2026-11-05', 'emailed', null],
        ['Addison Lee', 'partnerships@addisonlee.com', '2026-11-05', 'emailed', null],
        ['Bekaert (Bridon) - Doncaster ropes site and Ropes Technology Centre', 'contact@bridon-bekaert.com', '2026-11-05', 'emailed', null],
        ['Sheffield DocFest (International Documentary Festival Sheffield)', 'info@sheffdocfest.com', '2026-11-05', 'emailed', null],
        ['Special Melted Products (SMP)', 'shfhrdept@smp.ltd', '2026-11-05', 'emailed', null],
        ['Clarity Business Travel (incl. Agiito and Evolvi)', 'salessupport@claritybt.com', '2026-11-06', 'emailed', null],
        ['Cooper & Turner', 'dave.edwards@cooperandturner.co.uk', '2026-11-06', 'emailed', null],
        ['Corporé Ltd', 'info@corpore.co.uk', '2026-11-06', 'emailed', null],
        ["Cutlers' Hall (Company of Cutlers in Hallamshire)", 'sales@cutlershall.co.uk', '2026-11-06', 'emailed', null],
        ['Danieli UK', 'info.uk@danieli.com', '2026-11-06', 'emailed', null],
        ['Blacklane', 'partner@blacklane.com', '2026-11-05', 'replied',
            'Replied 5 Nov: Sheffield is not on their city list yet but they review quality operators; apply via the Blacklane partner portal (self-register).'],
        ['CMS (Sheffield office)', 'tanya.holt@cms-cmno.com', '2026-11-06', 'replied',
            'Replied 6 Nov: Tanya Holt (Partner) copied Julie Pepper (Julie.Pepper@cms-cmno.com) to point us in the right direction. Reply to Julie.'],
        ['Boeing Sheffield', 'SheffieldReception@exchange.boeing.com', '2026-11-05', 'not_interested',
            'Replied 5 Nov: no executive transport needed (hire/pool cars; VIP visits arranged by their own office).'],
    ];

    /** The subject actually used, so the follow-up's "Re:" matches what they received. */
    private const SUBJECTS = [
        'enquiries@flintbishop.co.uk' => 'Corporate chauffeur account',
        'travel.solutions@firstbus.co.uk' => 'Reciprocal partnership: Sheffield chauffeur operator',
        'supplier.relations@cmacgroup.co.uk' => 'Reciprocal partnership: Sheffield chauffeur operator',
        'info@birchwoodandcompany.com' => 'Corporate chauffeur account',
        'charles@scschauffeur.co.uk' => 'Regional Chauffeur Partnership | South Yorkshire',
        'uk_enquiries@rolls-royce.com' => 'Executive Transport Services | Rolls-Royce Rotherham',
        'sales@sfel.com' => 'Executive travel for your Brightside Lane site',
        'sales@carey.com' => 'UK Alliance Partner Enquiry | Sheffield & South Yorkshire',
        'corpevents@sheffield.ac.uk' => 'Transport for conference speakers and delegates',
        'partnerships@addisonlee.com' => 'Regional Chauffeur Partnership Enquiry | Sheffield & South Yorkshire',
        'contact@bridon-bekaert.com' => 'Corporate Executive Transport Services | Bekaert Doncaster',
        'info@sheffdocfest.com' => 'Transport for DocFest guests and speakers',
        'shfhrdept@smp.ltd' => 'Corporate Executive Transport Services | Special Melted Products',
        'salessupport@claritybt.com' => 'Local chauffeur supplier: Sheffield and Doncaster',
        'dave.edwards@cooperandturner.co.uk' => 'Executive travel for your Templeborough team',
        'info@corpore.co.uk' => 'Transport for your South Yorkshire clients',
        'sales@cutlershall.co.uk' => "Transport for Cutlers' Hall speakers and guests",
        'info.uk@danieli.com' => 'Executive travel for your Rotherham team',
    ];

    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('prospects')) {
            return;
        }

        foreach (self::SENT as [$company, $email, $date, $outcome, $note]) {
            $p = Prospect::whereRaw('lower(contact_email) = ?', [strtolower($email)])->first()
                ?? Prospect::where('company', $company)->first();
            if (! $p) {
                continue;
            }
            $allowed = $outcome === 'emailed' ? ['new'] : ['new', 'emailed'];
            if (! in_array($p->status, $allowed, true)) {
                continue;
            }

            $sent = Carbon::parse($date.' 12:00', config('app.timezone'));
            $from = $p->statusLabel();
            if (blank($p->contact_email)) {
                $p->contact_email = $email;
            }
            $p->last_contacted_at ??= $sent;
            $p->status = $outcome;

            if ($outcome === 'emailed') {
                if ($subject = self::SUBJECTS[strtolower($email)] ?? null) {
                    $p->email_subject = $subject;
                }
                $p->next_action_at = Prospect::workingDay($sent->copy()->addDays(4))->toDateString();
                $p->save();
                $p->log('emailed', 'First email sent by hand from Outlook on '.$sent->format('D j M').' to '.$email
                    .' (recorded from Sent Items, 9 Nov). Day-4 follow-up due '.Carbon::parse($p->next_action_at)->format('D j M').'.');
            } else {
                $p->replied_at ??= $sent;
                $p->next_action_at = null;
                $p->save();
                $p->log('status', $from.' → '.$p->statusLabel().' · '.$note);
            }
        }
    }

    public function down(): void
    {
    }
};
