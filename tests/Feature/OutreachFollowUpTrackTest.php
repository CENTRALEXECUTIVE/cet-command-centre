<?php

namespace Tests\Feature;

use App\Models\Prospect;
use App\Services\Outreach\OutreachEmailComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Supplier/partner prospects (managed ground transport, transfer platforms) must
 * get the PARTNER follow-ups, not the "corporate chauffeur account" ones (CMAC's
 * day-4 follow-up wrongly pitched a corporate account, 9 Nov 2026).
 */
class OutreachFollowUpTrackTest extends TestCase
{
    use RefreshDatabase;

    public function test_ground_transport_and_transfer_platforms_get_partner_follow_ups(): void
    {
        $c = app(OutreachEmailComposer::class);
        foreach ([
            'Managed ground transport (rail disruption / airline / emergency)',
            'Airport transfer platform',
            'Airport transfer marketplace',
            'Coach-led managed transport (rail, events, corporate)',
            'Corporate ground transport distribution (GDS/OBT)',
        ] as $segment) {
            $p = new Prospect(['company' => 'X', 'segment' => $segment]);
            $this->assertSame('partner', $c->track($p), $segment);
        }
        $this->assertSame('corporate', $c->track(new Prospect(['company' => 'Y', 'segment' => 'Law'])));
    }

    public function test_cmac_day_4_follow_up_is_about_partnership_not_a_corporate_account(): void
    {
        $p = Prospect::create(['company' => 'CMAC Group', 'segment' => 'Managed ground transport (rail disruption / airline / emergency)',
            'status' => 'emailed', 'contact_email' => 'supplier.relations@cmacgroup.co.uk', 'fit_score' => 5]);
        $body = app(OutreachEmailComposer::class)->followUps($p)['day_4']['body'];

        $this->assertStringNotContainsString('corporate chauffeur account', $body);
        $this->assertStringContainsString('partnership', $body);
    }

    public function test_a_name_is_only_used_when_the_address_reaches_that_person(): void
    {
        $c = app(OutreachEmailComposer::class);
        $mk = fn ($name, $email) => Prospect::create(['company' => 'University of Sheffield AMRC', 'town' => $email, 'segment' => 'Research',
            'status' => 'new', 'contact_name' => $name, 'contact_email' => $email, 'fit_score' => 5,
            'email_subject' => 'Hello', 'email_body' => "Dear [Name],\n\nBody.\n\nKind regards,"]);

        $this->assertStringStartsWith('Dear University of Sheffield AMRC Team,', $c->firstEmail($mk('Prof Ben Morgan (CEO)', 'enquiries@amrc.co.uk'))['body']);
        $this->assertStringStartsWith('Dear University of Sheffield AMRC Team,', $c->firstEmail($mk('Garry Jones', 'charles@scschauffeur.co.uk'))['body']);
        $this->assertStringStartsWith('Dear Ben,', $c->firstEmail($mk('Ben Morgan', 'ben.morgan@amrc.co.uk'))['body']);
        $this->assertStringStartsWith('Dear Andy,', $c->firstEmail($mk('Andy Smith', 'andrew@example.com'))['body']);
        $this->assertStringStartsWith('Dear Jo,', $c->firstEmail($mk('Jo Bloggs', 'jbloggs@example.com'))['body']);
    }

    public function test_follow_ups_name_the_company_without_the_bracketed_site(): void
    {
        $p = Prospect::create(['company' => 'Rolls-Royce (Advanced Blade Casting Facility)', 'segment' => 'Aerospace manufacturing',
            'status' => 'emailed', 'contact_email' => 'uk_enquiries@rolls-royce.com', 'fit_score' => 5]);
        $body = app(OutreachEmailComposer::class)->followUps($p)['day_4']['body'];

        $this->assertStringContainsString('corporate chauffeur account for Rolls-Royce.', $body);
        $this->assertStringNotContainsString('(Advanced Blade Casting Facility)', $body);
    }
}
