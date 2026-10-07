<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\Heartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_heartbeat_stamp_and_read(): void
    {
        $this->assertNull(Heartbeat::last('scheduler'));
        Heartbeat::stamp('scheduler');
        $this->assertNotNull(Heartbeat::last('scheduler'));
        $this->assertLessThanOrEqual(1, Heartbeat::ageMinutes('scheduler'));
    }

    public function test_scheduler_is_not_stale_before_it_has_ever_run(): void
    {
        // Brand-new install: never stamped → don't cry wolf.
        $this->assertFalse(Heartbeat::schedulerStale());
    }

    public function test_scheduler_is_stale_when_the_beat_is_old(): void
    {
        Setting::set('heartbeat.scheduler', now()->subMinutes(10)->toIso8601String(), 'string', 'system');
        $this->assertTrue(Heartbeat::schedulerStale());
    }

    public function test_the_heartbeat_command_stamps(): void
    {
        $this->artisan('cet:heartbeat')->assertSuccessful();
        $this->assertNotNull(Heartbeat::last('scheduler'));
    }

    public function test_admin_sees_the_health_page(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('health.index'))->assertOk()
            ->assertSee('System health')
            ->assertSee('Scheduler (cron)')
            ->assertSee('Database')
            ->assertSee('Database backups');
    }

    public function test_a_driver_cannot_see_the_health_page(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->get(route('health.index'))->assertForbidden();
    }

    public function test_the_scheduler_down_state_shows_on_the_health_page(): void
    {
        $admin = User::factory()->admin()->create();
        Setting::set('heartbeat.scheduler', now()->subMinutes(10)->toIso8601String(), 'string', 'system');

        // The warning lives on System Health (admin-only diagnostics), not nagging
        // every page.
        $this->actingAs($admin)->get(route('health.index'))->assertOk()
            ->assertSee('cron is NOT running', false);
    }

    public function test_the_dashboard_never_shows_the_down_banner(): void
    {
        $admin = User::factory()->admin()->create();
        Setting::set('heartbeat.scheduler', now()->subMinutes(10)->toIso8601String(), 'string', 'system');

        // Even when the scheduler is dead, the dashboard stays clean.
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()
            ->assertDontSee('Background jobs have stopped');
    }

    public function test_a_missing_page_shows_the_branded_error_page(): void
    {
        $this->get('/definitely-not-a-real-page-'.uniqid())
            ->assertNotFound()
            ->assertSee('Back to the Command Centre');
    }
}
