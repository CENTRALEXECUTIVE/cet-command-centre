<?php

use App\Models\User;
use App\Services\Outreach\ContactNameCorrections;
use App\Services\Outreach\ProspectImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ONE-OFF data load, applied by auto-deploy's `migrate --force`. The second
 * research pass added more published contact emails to
 * docs/growth/sales/contacts.csv; 2026_11_02_000001 has already run, so this
 * re-reads the file in FILL-ONLY mode (blank fields only, nothing created,
 * status/notes/owner/dates and do-not-contact never touched, nothing sent).
 * Rows loaded last time are no-ops.
 *
 * It also fixes four greetings that would have addressed the wrong person:
 * only when the current contact_name still starts with the old researched
 * value (a director's edit is never overwritten). No schema change.
 * Guarded to a no-op in tests.
 */
return new class extends Migration
{
    private const NAME_FIXES = [
        [
            'company' => 'University of Sheffield AMRC',
            'town' => 'Advanced Manufacturing Park, Rotherham (Catcliffe/Waverley)',
            'old_prefix' => 'Prof Ben Morgan',
            'new_name' => null,
            'reason' => 'email goes to the general enquiries inbox (enquiries@amrc.co.uk)',
        ],
        [
            'company' => 'DLA Piper (Sheffield office)',
            'town' => 'Elshaw House, Heart of the City, Sheffield',
            'old_prefix' => 'Petra Billing',
            'new_name' => 'Amanda Pilkington',
            'reason' => 'Office Managing Partner Sheffield per her published DLA Piper profile; the email is hers',
        ],
        [
            'company' => 'Cooper & Turner',
            'town' => 'Templeborough Works, Sheffield Road, Rotherham/Sheffield',
            'old_prefix' => 'Anthony Brown',
            'new_name' => 'Dave Edwards',
            'reason' => 'General Manager UK Manufacturing per the company directory listing; the email is his',
        ],
        [
            'company' => 'Chesterfield FC',
            'town' => 'Chesterfield (SMH Group Stadium)',
            'old_prefix' => 'Bridget Ball',
            'new_name' => null,
            'reason' => 'email goes to the club reception inbox (reception@chesterfield-fc.co.uk)',
        ],
    ];

    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('prospects')) {
            return;
        }

        $owner = User::where('email', 'admin@centralexecutivetransfers.co.uk')->first();
        if (! $owner || ! $owner->isAdmin()) {
            $owner = User::where('is_super_admin', true)->orderBy('id')->first();
        }

        // Names first, so the fill-only load can't fill a cleared name back in.
        $names = app(ContactNameCorrections::class)->apply(self::NAME_FIXES, $owner?->id);
        Log::info('Outreach greeting fixes', $names);

        $path = base_path('docs/growth/sales/contacts.csv');
        if (is_file($path)) {
            $stats = app(ProspectImporter::class)->import($path, $owner?->id, $owner?->id, fillOnly: true);
            Log::info('Outreach contact emails loaded (pass 2)', array_diff_key($stats, ['errors' => 1]) + ['errors' => array_slice($stats['errors'], 0, 20)]);
        }
    }

    public function down(): void
    {
        // Data load only.
    }
};
