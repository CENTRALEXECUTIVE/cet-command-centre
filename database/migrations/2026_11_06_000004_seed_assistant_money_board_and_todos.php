<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * ONE-OFF: pre-fill "My assistant" with the figures the directors gave on
 * 6 Nov 2026, ONLY if nothing has been entered yet (the office's own entries
 * always win). No schema change. Guarded to a no-op in tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('settings')) {
            return;
        }

        if (blank(Setting::get('pa_money'))) {
            Setting::set('pa_money', [
                'ltd' => 296.16, 'pvt' => 593.72, 'incoming' => 177.10,
                'owed_to_us' => [['label' => 'Company (cover/account work), due soon', 'amount' => 620.0]],
                'we_owe' => [
                    ['label' => 'Abdi, last week (pay after external drivers)', 'amount' => 900.0],
                    ['label' => 'Abdi, older (can wait)', 'amount' => 1000.0],
                ],
                'updated_at' => '2026-11-06 16:00:00', 'updated_by' => 'Claude (from chat)',
            ], 'json', 'assistant');
        }

        if (blank(Setting::get('pa_todos'))) {
            $todo = fn (string $t, ?string $due = null) => ['id' => (string) Str::uuid(), 'text' => $t, 'due' => $due,
                'done' => false, 'by' => 'Claude', 'at' => '2026-11-06 16:00:00'];
            Setting::set('pa_todos', [
                $todo('Chase the £620 if not received', '2026-11-11'),
                $todo('Ask the accountant about VAT (no VAT payments seen Jun to Oct)', '2026-11-13'),
                $todo('Set up a Tide Budget for the drivers\' share of prepaid jobs', '2026-11-10'),
                $todo('Send Matt Bruce (Hawsons) the personal follow-up email', '2026-11-09'),
                $todo('First outreach batch: Outreach → Today\'s batch (10 emails)', '2026-11-09'),
                $todo('Decide: stay no-ads or £10/day test (check weeks of 9 and 16 Nov)', '2026-11-20'),
                $todo('Schedule the 9 to 15 Nov social posts; film Sunday clips', '2026-11-08'),
                $todo('Confirm this week\'s external driver pay total'),
            ], 'json', 'assistant');
        }
    }

    public function down(): void
    {
        // Data only.
    }
};
