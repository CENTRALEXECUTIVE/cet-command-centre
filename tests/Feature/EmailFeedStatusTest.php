<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\EmailFeedStatus;
use App\Support\Heartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The ETO email-feed status panel: tells the office why new bookings do or don't
 * auto-add, and which requirement is missing.
 */
class EmailFeedStatusTest extends TestCase
{
    use RefreshDatabase;

    private function connectEverything(): void
    {
        config([
            'services.microsoft_graph.client_id' => 'id',
            'services.microsoft_graph.client_secret' => 'secret',
            'services.microsoft_graph.tenant_id' => 'tenant',
            'services.microsoft_graph.mailbox' => 'admin@centralexecutivetransfers.co.uk',
            'services.anthropic.key' => 'sk-test',
        ]);
        Heartbeat::stamp('scheduler'); // fresh beat → not stale
    }

    public function test_it_is_not_connected_when_credentials_are_missing(): void
    {
        config(['services.microsoft_graph.client_id' => null]);

        $feed = new EmailFeedStatus;
        $this->assertFalse($feed->connected());
        $this->assertSame('Microsoft 365 connection', $feed->firstProblem());
    }

    public function test_the_default_bookings_mailbox_counts_as_not_set_up(): void
    {
        config([
            'services.microsoft_graph.client_id' => 'id',
            'services.microsoft_graph.client_secret' => 'secret',
            'services.microsoft_graph.tenant_id' => 'tenant',
            'services.microsoft_graph.mailbox' => 'bookings@centralexecutivetransfers.co.uk',
            'services.anthropic.key' => 'sk-test',
        ]);
        Heartbeat::stamp('scheduler');

        $feed = new EmailFeedStatus;
        $this->assertFalse($feed->connected());
        $this->assertSame('Reading the right inbox', $feed->firstProblem());
    }

    public function test_it_is_connected_when_everything_is_set(): void
    {
        $this->connectEverything();

        $feed = new EmailFeedStatus;
        $this->assertTrue($feed->connected());
        $this->assertNull($feed->firstProblem());
        $this->assertSame('Live', $feed->summary());
    }

    public function test_it_reports_the_last_run_and_stats(): void
    {
        Setting::set('outlook_ingest_last_run', now()->subMinutes(2)->toIso8601String(), 'string', 'system');
        Setting::set('outlook_ingest_last_stats', json_encode(['processed' => 5, 'created' => 2, 'updated' => 1, 'cancelled' => 0, 'skipped' => 2]), 'json', 'system');

        $feed = new EmailFeedStatus;
        $this->assertNotNull($feed->lastRun());
        $this->assertSame(2, $feed->lastStats()['created']);
    }

    public function test_the_dashboard_warns_when_the_feed_is_off(): void
    {
        config(['services.microsoft_graph.client_id' => null]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('dashboard'))->assertOk()
            ->assertSee("ETO email feed is off", false);
    }

    public function test_the_health_page_shows_the_feed_panel(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('health.index'))->assertOk()
            ->assertSee('ETO email feed', false)
            ->assertSee('Microsoft 365 connection', false);
    }
}
