<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Calendar\CalendarHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarSyncHealthTest extends TestCase
{
    use RefreshDatabase;

    private function health(): CalendarHealth
    {
        return app(CalendarHealth::class);
    }

    public function test_never_synced_is_not_flagged_stale(): void
    {
        // No successful sync recorded yet → don't nag (a fresh/never-configured install).
        $this->assertFalse($this->health()->isStale());
    }

    public function test_a_recent_sync_is_not_stale(): void
    {
        Setting::set('calendar_last_sync_ok', now()->subMinutes(3)->toIso8601String());
        $this->assertFalse($this->health()->isStale());
    }

    public function test_an_old_sync_is_stale(): void
    {
        Setting::set('calendar_last_sync_ok', now()->subHours(3)->toIso8601String());
        $this->assertTrue($this->health()->isStale());
        $this->assertNotNull($this->health()->ageForHumans());
    }

    public function test_admin_sees_the_stale_warning_banner(): void
    {
        Setting::set('calendar_last_sync_ok', now()->subHours(3)->toIso8601String());

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Calendar sync is behind');
    }

    public function test_no_banner_when_sync_is_fresh(): void
    {
        Setting::set('calendar_last_sync_ok', now()->subMinutes(2)->toIso8601String());

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Calendar sync is behind');
    }
}
