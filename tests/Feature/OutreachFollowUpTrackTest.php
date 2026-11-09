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
}
