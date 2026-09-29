<?php

use App\Models\CorporateAccount;
use Illuminate\Database\Migrations\Migration;

// One-off: set up the MEPS International Ltd business account (account no. 1001)
// with its authorised contacts, so they can book on account (monthly invoice).
// Idempotent (keyed by account_code) and skipped under testing so it never
// pollutes the suite's data expectations.
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        // Keyed by slug so re-runs never duplicate it; the account NUMBER is a
        // professional randomised code assigned once on first create.
        $account = CorporateAccount::firstOrNew(['slug' => 'meps-international']);
        if (! $account->exists) {
            $account->account_code = CorporateAccount::generateAccountCode();
        }
        $account->fill([
            'name' => 'MEPS International Ltd',
            'company_number' => '02060184',
            'billing_email' => 'lroberts@meps.co.uk',
            'phone' => '+44 114 275 0570',
            'billing_address' => "MEPS International Ltd\n263 Glossop Road\nSheffield\nS10 2GZ",
            'vat_number' => 'GB 439097618',
            'cost_code_required' => false,
            'payment_terms_days' => 30,
            'is_active' => true,
            'notes' => "Monthly invoice account. Invoices to Lorna Roberts (lroberts@meps.co.uk) & Jayne Craven (jcraven@meps.co.uk). Set up 29 Sep 2026.",
        ]);
        $account->save();

        $contacts = [
            ['name' => 'Lorna Roberts', 'email' => 'lroberts@meps.co.uk', 'phone' => '07712129000', 'job_title' => 'Head of HR and Executive Assistant', 'is_primary' => true],
            ['name' => 'Jayne Craven', 'email' => 'jcraven@meps.co.uk', 'is_primary' => false],
            ['name' => 'Julie Hurley', 'email' => 'julie.hurley@meps.co.uk', 'is_primary' => false],
            ['name' => 'Julie Hurley (Gmail)', 'email' => 'juliehurley707@gmail.com', 'is_primary' => false],
            ['name' => 'Stella Fish', 'email' => null, 'is_primary' => false],
        ];

        foreach ($contacts as $c) {
            $account->contacts()->updateOrCreate(
                ['name' => $c['name']],
                [
                    'email' => $c['email'] ?? null,
                    'phone' => $c['phone'] ?? null,
                    'job_title' => $c['job_title'] ?? null,
                    'is_primary' => $c['is_primary'] ?? false,
                ],
            );
        }
    }

    public function down(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $account = CorporateAccount::where('slug', 'meps-international')->first();
        $account?->contacts()->delete();
        $account?->delete();
    }
};
