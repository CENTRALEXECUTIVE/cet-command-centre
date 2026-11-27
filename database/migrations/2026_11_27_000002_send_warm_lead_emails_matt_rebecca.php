<?php

use App\Mail\OutreachMail;
use App\Models\Prospect;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * 27 Nov 2026 (directors, in chat: "send them 2 from admin email as usual"): personal
 * emails to two warm leads — Matt Bruce (Hawsons; met Abdi in person) and Rebecca
 * Fielding (Gradconsult; past customer). Sent once from admin@ in the outreach style +
 * signature. Both are then tracked as prospects (status Emailed) so the normal day-4 /
 * day-10 follow-ups run and the generic cold Hawsons email never goes. No-op in tests.
 */
return new class extends Migration
{
    private const SERVICE = "Central Executive Transfers Ltd is VAT registered, so every journey comes with a proper VAT invoice your business can reclaim.\n\n"
        ."Our corporate service includes:\n\n"
        ."- Mercedes-Benz executive cars, V-Class vehicles and minibuses\n"
        ."- 24/7 availability for business, rail and airport transfers\n"
        ."- Professional, licensed chauffeurs and fixed, pre-agreed fares\n"
        ."- Flight monitoring and meet-and-greet services\n"
        ."- A business account, so bookings are quick and invoiced monthly on 30-day terms\n"
        ."- VAT invoices with PO or client references where required\n\n";

    public function up(): void
    {
        if (app()->environment('testing') || Setting::get('warm_leads_27nov_sent')) {
            return;
        }

        $emails = [
            [
                'to' => 'matt.bruce@hawsons.co.uk', 'name' => 'Matt Bruce', 'company' => 'Hawsons', 'match' => '%Hawsons%',
                'subject' => 'Executive travel for Hawsons',
                'body' => "Dear Matt,\n\n"
                    ."I'm reaching out from Central Executive Transfers, a Sheffield-based executive chauffeur company supporting businesses across South Yorkshire and nationwide. Our director, Abdi, enjoyed speaking with you recently and mentioned Hawsons might find us useful.\n\n"
                    ."With your offices in Sheffield, Doncaster and Northampton, we'd welcome the opportunity to support your partners and team with airport transfers, client meetings and travel between offices.\n\n"
                    .self::SERVICE
                    ."We also offer dedicated hourly chauffeur hire for client meetings, multi-stop days and business events.\n\n"
                    ."You can open a business account with us here: https://centralexecutivetransfers.co.uk/open-account\n\n"
                    ."Or book online at www.centralexecutivetransfers.co.uk, or simply email us at admin@centralexecutivetransfers.co.uk.\n\n"
                    ."Kind regards,",
            ],
            [
                'to' => 'rebecca@gradconsult.co.uk', 'name' => 'Rebecca Fielding', 'company' => 'Gradconsult', 'match' => '%Gradconsult%',
                'subject' => 'Executive travel for Gradconsult',
                'body' => "Dear Rebecca,\n\n"
                    ."Thank you for travelling with Central Executive Transfers this year. We always enjoy looking after you.\n\n"
                    ."As a Sheffield-based executive chauffeur company supporting businesses across South Yorkshire and nationwide, we'd welcome the opportunity to support Gradconsult with travel for your team and clients, as well as your own trips.\n\n"
                    .self::SERVICE
                    ."We also offer dedicated hourly chauffeur hire for client meetings, multi-stop days and business events. We'd be equally happy to set up an account for your partner's company, so please feel free to pass our details on.\n\n"
                    ."You can open a business account with us here: https://centralexecutivetransfers.co.uk/open-account\n\n"
                    ."Or book online at www.centralexecutivetransfers.co.uk whenever you need us.\n\n"
                    ."Kind regards,",
            ],
        ];

        $owner = User::where('email', 'admin@centralexecutivetransfers.co.uk')->first()
            ?? User::where('is_super_admin', true)->orderBy('id')->first();
        $sent = 0;

        foreach ($emails as $e) {
            try {
                Mail::to($e['to'])->send(new OutreachMail($e['subject'], $e['body']));
                $sent++;
            } catch (\Throwable $ex) {
                Log::warning('[outreach] warm lead email failed for '.$e['to'].': '.$ex->getMessage());

                continue;
            }

            if (! Schema::hasTable('prospects')) {
                continue;
            }
            $p = Prospect::where('company', 'like', $e['match'])->first() ?? new Prospect([
                'company' => $e['company'], 'segment' => 'Warm lead', 'town' => 'Sheffield', 'fit_score' => 5, 'owner_id' => $owner?->id,
            ]);
            $p->fill([
                'contact_name' => $e['name'], 'contact_email' => $e['to'],
                'email_subject' => $e['subject'], 'email_body' => $e['body'],
                'status' => 'emailed', 'last_contacted_at' => now(), 'next_action_at' => now()->addDays(4),
            ])->save();
            $p->log('email', 'Personal warm-lead email sent from admin@ (27 Nov 2026): '.$e['subject']);
        }

        if ($sent > 0) {
            Setting::set('warm_leads_27nov_sent', now()->toIso8601String(), 'string', 'outreach');
        }
    }

    public function down(): void
    {
    }
};
