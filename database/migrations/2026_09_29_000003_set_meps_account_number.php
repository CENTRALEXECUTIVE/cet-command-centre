<?php

use App\Models\CorporateAccount;
use Illuminate\Database\Migrations\Migration;

// Give MEPS International Ltd a clean, memorable account number (MP1001) — the
// number the office sends the customer. Idempotent (by slug); skipped in tests.
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $meps = CorporateAccount::where('slug', 'meps-international')->first();
        if ($meps && $meps->account_code !== 'MP1001'
            && ! CorporateAccount::where('account_code', 'MP1001')->where('id', '!=', $meps->id)->exists()) {
            $meps->forceFill(['account_code' => 'MP1001'])->save();
        }
    }

    public function down(): void
    {
        // No-op — the account number is business data, not restorable.
    }
};
