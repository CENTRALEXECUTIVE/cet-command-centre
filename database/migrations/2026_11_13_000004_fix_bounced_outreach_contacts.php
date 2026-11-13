<?php

use App\Models\Prospect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * 13 Nov 2026 outreach bounces:
 *  - Chesterfield Special Cylinders: info@chesterfieldcylinders.com doesn't exist (550 5.1.1).
 *    Their group's own contact page lists enquiries@csc-holdings.com (Google mail, live).
 *    Switch to it and put them back to "new" so the FIRST email goes again in the next batch.
 *  - MJ Gleeson: enquiries@mjgleeson.com auto-replies "not monitored"; no business contact
 *    published. Hold (no address, no follow-up) until the office gets one from reception
 *    (0114 261 2900).
 *  - Parker Bestobell: bestobellvalves.com has no mail server at all (no MX), so
 *    info@bestobellvalves.com can never arrive. Hold until a parker.com contact is found.
 * No-op in tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('prospects')) {
            return;
        }

        Prospect::where('contact_email', 'info@chesterfieldcylinders.com')->get()->each(function (Prospect $p) {
            $p->forceFill(['contact_email' => 'enquiries@csc-holdings.com', 'status' => 'new', 'next_action_at' => null])->save();
            $p->log('note', 'info@chesterfieldcylinders.com bounced on 13 Nov (address does not exist). Switched to enquiries@csc-holdings.com (group contact page); first email will go again in the next batch.');
        });

        Prospect::where('contact_email', 'info@bestobellvalves.com')->get()->each(function (Prospect $p) {
            $p->forceFill(['contact_email' => null, 'next_action_at' => null])->save();
            $p->log('note', 'bestobellvalves.com has no mail server (checked 13 Nov), so info@ would bounce. On hold until a Parker contact is found.');
        });

        Prospect::where('contact_email', 'enquiries@mjgleeson.com')->get()->each(function (Prospect $p) {
            $p->forceFill(['contact_email' => null, 'next_action_at' => null])->save();
            $p->log('note', 'enquiries@mjgleeson.com auto-replied on 13 Nov: mailbox not monitored. On hold — ask reception (0114 261 2900) for the office/travel contact.');
        });
    }

    public function down(): void
    {
    }
};
