<?php

namespace Tests\Feature;

use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Directors (11 Nov 2026): the outreach page shows the TOTAL emails sent, and every
 * stat box is a link that opens the list behind its number.
 */
class OutreachStatBoxesTest extends TestCase
{
    use RefreshDatabase;

    private function prospect(string $company, array $a = []): Prospect
    {
        return Prospect::create($a + [
            'company' => $company, 'town' => 'Sheffield', 'contact_email' => strtolower(str_replace(' ', '', $company)).'@example.test',
            'status' => 'new', 'fit_score' => 3,
        ]);
    }

    public function test_total_emails_sent_counts_every_email_and_each_box_filters_the_list(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $replied = $this->prospect('Replied Ltd', ['status' => 'replied', 'last_contacted_at' => now(), 'replied_at' => now()]);
        $replied->log('emailed', 'First email');
        $replied->log('emailed', 'Follow-up');
        $emailed = $this->prospect('Emailed Ltd', ['status' => 'emailed', 'last_contacted_at' => now()]);
        $emailed->log('emailed', 'First email');
        $this->prospect('Fresh Ltd');
        $this->prospect('Meeting Ltd', ['status' => 'meeting']);

        $page = $this->get(route('outreach.index'))->assertOk();
        $page->assertSee('Total emails sent');
        // 3 emails out (2 to Replied Ltd, 1 to Emailed Ltd).
        $this->assertMatchesRegularExpression('/kpi-n">3<\/div><div class="kpi-l">Total emails sent/', $page->getContent());
        foreach (['sent', 'week', 'replied', 'meetings', 'accounts', 'contacted'] as $v) {
            $page->assertSee('view='.$v, false);
        }

        $this->get(route('outreach.index', ['view' => 'replied']))->assertOk()
            ->assertSee('Replied Ltd')->assertDontSee('Emailed Ltd')->assertDontSee('Fresh Ltd');
        $this->get(route('outreach.index', ['view' => 'sent']))->assertOk()
            ->assertSee('Replied Ltd')->assertSee('Emailed Ltd')->assertDontSee('Fresh Ltd');
        $this->get(route('outreach.index', ['view' => 'week']))->assertOk()
            ->assertSee('Emailed Ltd')->assertDontSee('Fresh Ltd');
        $this->get(route('outreach.index', ['view' => 'meetings']))->assertOk()
            ->assertSee('Meeting Ltd')->assertDontSee('Fresh Ltd');
    }
}
