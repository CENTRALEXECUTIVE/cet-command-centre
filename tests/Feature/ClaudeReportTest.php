<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Read-only outreach report for Claude (Nov 2026): locked by a key a super admin
 * makes on Settings → Claude access; only the hash is stored; outreach data only.
 */
class ClaudeReportTest extends TestCase
{
    use RefreshDatabase;

    private function makeKey(): string
    {
        $super = User::factory()->admin()->create(['is_super_admin' => true]);
        $this->actingAs($super)->post(route('claude-access.generate'))->assertRedirect(route('claude-access.index'));
        $key = session('claude_new_key');
        $this->assertStringStartsWith('cet_', $key);
        auth()->logout();

        return $key;
    }

    public function test_no_key_set_means_the_report_does_not_exist(): void
    {
        $this->getJson(route('claude.report.outreach'))->assertNotFound();
        $this->getJson(route('claude.report.outreach'), ['Authorization' => 'Bearer anything'])->assertNotFound();
    }

    public function test_the_right_key_reads_todays_outreach_and_a_wrong_key_gets_nothing(): void
    {
        $key = $this->makeKey();
        Prospect::create(['company' => 'Acme Forge', 'town' => 'Sheffield', 'contact_email' => 'sales@acme.test',
            'email_subject' => 'Executive travel', 'email_body' => "Dear [Name],\n\nHello.\n\nKind regards,",
            'status' => 'new', 'fit_score' => 5]);

        $this->getJson(route('claude.report.outreach'), ['Authorization' => 'Bearer wrong'])->assertNotFound();

        $r = $this->getJson(route('claude.report.outreach'), ['Authorization' => 'Bearer '.$key])->assertOk();
        $r->assertJsonStructure(['today' => ['sent', 'remaining', 'daily_limit'], 'sent_today', 'queued',
            'batch_waiting_for_approval', 'replies_last_14_days', 'follow_ups_due_next_7_days', 'pipeline']);
        if (! $r->json('today.weekend')) {
            $this->assertSame('Acme Forge', $r->json('batch_waiting_for_approval.0.company'));
        }
        $this->assertSame(1, (int) $r->json('pipeline.new'));
    }

    public function test_the_report_never_includes_bookings_or_customers(): void
    {
        $key = $this->makeKey();
        Booking::factory()->create();
        $body = $this->getJson(route('claude.report.outreach'), ['Authorization' => 'Bearer '.$key])->assertOk()->getContent();
        $this->assertStringNotContainsString('pickup', strtolower($body));
        $this->assertStringNotContainsString('customer', strtolower($body));
    }

    public function test_only_the_hash_is_stored_and_turning_off_kills_the_key(): void
    {
        $key = $this->makeKey();
        $this->assertDatabaseMissing('settings', ['value' => $key]);

        $super = User::where('is_super_admin', true)->first();
        $this->actingAs($super)->post(route('claude-access.revoke'))->assertRedirect();
        auth()->logout();
        $this->getJson(route('claude.report.outreach'), ['Authorization' => 'Bearer '.$key])->assertNotFound();
    }

    public function test_a_normal_admin_cannot_make_a_key(): void
    {
        $this->actingAs(User::factory()->admin()->create(['is_super_admin' => false]))
            ->post(route('claude-access.generate'))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create(['is_super_admin' => true]))
            ->get(route('claude-access.index'))->assertOk()->assertSee('Claude access');
    }

    public function test_booking_lookup_is_key_locked_and_masks_customer_details(): void
    {
        $customer = \App\Models\Customer::create(['name' => 'Jess Arthur', 'email' => 'jarthur@example.co.uk', 'phone' => '07700900333']);
        $booking = Booking::factory()->create(['customer_id' => $customer->id, 'reference' => 'CET-ABC12A', 'pickup_at' => now()->addDay()]);
        \App\Support\BookingEmailLog::record($booking, ['type' => 'Paid confirmation', 'audience' => 'customer',
            'to' => 'jarthur@example.co.uk', 'subject' => 'x', 'ok' => true]);

        $this->getJson(route('claude.report.booking', ['ref' => 'CET-ABC12A']))->assertNotFound();

        $key = $this->makeKey();
        $res = $this->getJson(route('claude.report.booking', ['ref' => 'cet-abc12a']), ['Authorization' => 'Bearer '.$key])
            ->assertOk()
            ->assertJsonPath('reference', 'CET-ABC12A')
            ->assertJsonPath('customer.name', 'Jess Arthur')
            ->assertJsonPath('customer.email', 'ja•••@example.co.uk')
            ->assertJsonPath('customer.online_account', false)
            ->assertJsonPath('emails.sent', 1)
            ->assertJsonPath('emails.history.0.to', 'ja•••@example.co.uk');

        $body = $res->getContent();
        $this->assertStringNotContainsString('07700900333', $body);
        $this->assertStringNotContainsString('jarthur@', $body);
    }
}
