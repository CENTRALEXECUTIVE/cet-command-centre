<?php

namespace Tests\Feature;

use App\Models\Prospect;
use App\Services\Outreach\OutreachEmailComposer;
use App\Services\Outreach\ProspectImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutreachBBraunLoadTest extends TestCase
{
    use RefreshDatabase;

    public function test_bbraun_row_imports_with_the_given_email_and_house_style_draft(): void
    {
        $importer = app(ProspectImporter::class);
        $importer->import(base_path('docs/growth/sales/batch-H-direct.csv'), null, null);

        $p = Prospect::where('company', 'B. Braun (UK head office)')->firstOrFail();
        $this->assertSame('customercare.bbmuk@bbraun.co.uk', $p->contact_email);

        $email = app(OutreachEmailComposer::class)->firstEmail($p);
        $this->assertStringStartsWith('Dear B. Braun Team,', $email['body']);
        $this->assertStringContainsString("I'm reaching out from Central Executive Transfers", $email['body']);
        $this->assertStringContainsString('enhanced DBS-checked', $email['body']);
        $this->assertStringNotContainsString('Abdi', $email['body']);
        $this->assertStringNotContainsString('—', $email['body']);

        // Loading again never duplicates.
        $importer->import(base_path('docs/growth/sales/batch-H-direct.csv'), null, null);
        $this->assertSame(1, Prospect::where('company', 'B. Braun (UK head office)')->count());
    }
}
