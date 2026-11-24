<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Directors (24 Nov 2026): "notify me if there's a call or message" on any masked line. */
class MaskedActivityAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_switchboard_call_with_no_live_job_still_alerts_the_directors(): void
    {
        config(['cet.webhook_secret' => 'sek']);
        User::factory()->admin()->create(['is_super_admin' => true]);
        $this->post(route('webhooks.voice'), ['secret' => 'sek', 'From' => '+447700900999'])->assertOk();
        $this->assertDatabaseHas('watchdog_events', ['event_type' => 'masked_call', 'booking_id' => null]);
    }

    public function test_a_proxy_message_not_tied_to_a_booking_alerts_too(): void
    {
        config(['cet.webhook_secret' => 'sek']);
        $this->post(route('webhooks.twilio-proxy'), ['secret' => 'sek', 'interactionType' => 'Message', 'inboundResourceType' => 'sms'])->assertOk();
        $this->assertDatabaseHas('watchdog_events', ['event_type' => 'masked_message']);
    }

    public function test_masking_health_report_is_key_locked_and_content_free(): void
    {
        $super = User::factory()->admin()->create(['is_super_admin' => true]);
        $this->actingAs($super)->post(route('claude-access.generate'));
        $key = session('claude_new_key');
        auth()->logout();

        $this->getJson(route('claude.report.masking'))->assertNotFound();
        $this->getJson(route('claude.report.masking'), ['Authorization' => 'Bearer '.$key])->assertOk()
            ->assertJsonStructure(['configured', 'sessions' => ['open_now', 'last_opened'], 'events', 'alerts_sent']);
    }
}
