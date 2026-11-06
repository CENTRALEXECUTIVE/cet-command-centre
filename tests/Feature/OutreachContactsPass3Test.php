<?php

namespace Tests\Feature;

use App\Models\Prospect;
use App\Services\Outreach\ProspectImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutreachContactsPass3Test extends TestCase
{
    use RefreshDatabase;

    public function test_contacts_file_fills_researched_emails_and_phones_only_where_blank(): void
    {
        $importer = app(ProspectImporter::class);
        foreach (glob(base_path('docs/growth/sales/{2026-10-28-prospects,batch-*}.csv'), GLOB_BRACE) as $file) {
            $importer->import($file);
        }
        // A director's own edit must survive the load.
        $leo = Prospect::where('company', 'Leopold Hotel Sheffield')->firstOrFail();
        $before = Prospect::count();
        $irwin = Prospect::where('company', 'Irwin Mitchell')->firstOrFail();
        $irwin->update(['contact_email' => 'office-set@example.test']);

        $stats = $importer->import(base_path('docs/growth/sales/contacts.csv'), fillOnly: true);

        $this->assertSame($before, Prospect::count(), 'a contacts file never adds prospects');
        $this->assertSame('sales.sheffield@leopoldhotels.com', $leo->fresh()->contact_email);
        $this->assertSame('office-set@example.test', $irwin->fresh()->contact_email);
        $this->assertNull(Prospect::where('company', 'DFS Furniture plc')->value('contact_email'), 'investor-relations inbox is not loaded');
        $this->assertGreaterThan(140, Prospect::whereNotNull('contact_email')->where('contact_email', '!=', '')->count());
        $this->assertGreaterThan(50, Prospect::whereNotNull('contact_phone')->where('contact_phone', '!=', '')->count());
        $this->assertSame(0, $stats['unmatched'] ?? 0);
    }
}
