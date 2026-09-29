<?php

use App\Models\CorporateAccount;
use Illuminate\Database\Migrations\Migration;

// Replace any legacy/placeholder account numbers (e.g. the interim "1001") with a
// professional randomised code. Runs once; skipped under testing.
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        // Upgrade purely-numeric interim codes (like 1001) to a proper code.
        CorporateAccount::withTrashed()
            ->get()
            ->filter(fn ($a) => ctype_digit((string) $a->account_code))
            ->each(function ($account) {
                $account->forceFill(['account_code' => CorporateAccount::generateAccountCode()])->save();
            });
    }

    public function down(): void
    {
        // No-op: the old numeric codes are not restorable and weren't meaningful.
    }
};
