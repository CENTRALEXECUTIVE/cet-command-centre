<?php

use App\Models\Prospect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Proof-read fixes for the 9 Nov batch (checked against each firm's own site,
 * Companies House and press): NCM UK's head office is in Todwick (not Kiveton
 * Park); AMRC line tidied; Castle Square goes to Kevan Shaw (Managing Partner,
 * kevan.shaw@ published on castlesquarecf.co.uk); IFA's mcarlisle@ is Michael
 * Carlisle (business development, published by the North West Aerospace
 * Alliance). Text edits only apply where the old wording is still there, so an
 * office edit is never overwritten. Only touches prospects still at "New".
 * No-op in tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('prospects')) {
            return;
        }

        $this->edit('NCM UK (Neuro Case Management UK)', [
            'providing discreet, reliable journeys across' => 'providing discreet journeys across',
            'With your head office based in Kiveton Park and case managers arranging client journeys to therapy, clinics and expert appointments, we\'d welcome the opportunity to support your team with reliable local transport.'
                => 'With your head office based in Todwick and case managers arranging client journeys to therapy, clinics and expert appointments, we\'d welcome the opportunity to support your team with dependable local transport.',
            '- Discreet, reliable door-to-door transport for patients, clients and families' => '- Door-to-door transport for clients and their families',
        ]);
        $this->edit('University of Sheffield AMRC', [
            'With the AMRC based on the Advanced Manufacturing Park and working with businesses throughout the year, we\'d'
                => 'With the AMRC based on the Advanced Manufacturing Park in Rotherham, we\'d',
        ], function (Prospect $p) {
            $p->contact_name = null; // shared inbox; not the CEO
            $p->company = 'AMRC (University of Sheffield)'; // greets "Dear AMRC Team,"
        });
        $this->edit('Castle Square Corporate Finance', [
            'Could you kindly direct me to the person responsible for arranging travel for your partners at Castle Square?'
                => 'Would you be open to a short conversation about supporting your partners\' travel, or could you direct me to the person who arranges it?',
        ], function (Prospect $p) {
            $p->contact_email = 'kevan.shaw@castlesquarecf.co.uk';
            $p->contact_name = 'Kevan Shaw';
        });
        $this->edit('Independent Forgings and Alloys (IFA)', [
            'and your expansion planned, we\'d' => 'and your planned expansion, we\'d',
        ], fn (Prospect $p) => $p->contact_name = 'Michael Carlisle');
    }

    private function edit(string $company, array $swaps, ?callable $also = null): void
    {
        $p = Prospect::where('company', $company)->where('status', 'new')->first();
        if (! $p) {
            return;
        }
        $body = (string) $p->email_body;
        foreach ($swaps as $from => $to) {
            $body = str_replace($from, $to, $body);
        }
        $p->email_body = $body;
        if ($also) {
            $also($p);
        }
        $p->save();
        $p->log('note', 'Draft proof-read and corrected (9 Nov 2026).');
    }

    public function down(): void
    {
    }
};
