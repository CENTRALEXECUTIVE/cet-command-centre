<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\DriverProfile;
use App\Models\JobNudge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The driver "getting ready / I'm on it" checkpoint. Roughly 30 min before
 * pickup the assigned driver is nudged to confirm they're on it; if they don't
 * confirm (and haven't set off) by the escalate window the office is alerted and
 * the emergency auto-call goes out. There is NO "can't make it" option — it's a
 * confirmation, never a decline. Setting off confirms it automatically.
 */
class GettingReadyCheckpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function driver(): User
    {
        $driver = User::factory()->driver()->create();
        DriverProfile::create(['user_id' => $driver->id, 'is_third_party' => true]);

        return $driver;
    }

    private function job(BookingStatus $status, Carbon $pickup, ?User $driver = null): Booking
    {
        return Booking::factory()->create([
            'driver_id' => ($driver ?? $this->driver())->id,
            'status' => $status,
            'pickup_at' => $pickup,
        ]);
    }

    private function tick(): void
    {
        $this->artisan('cet:status-watchdog')->assertSuccessful();
    }

    /* ── Model ───────────────────────────────────────────────────────────── */

    public function test_a_fresh_job_is_not_confirmed_then_confirming_sticks(): void
    {
        $b = $this->job(BookingStatus::Allocated, now()->addMinutes(25));

        $this->assertFalse($b->gettingReadyConfirmed());

        $b->confirmGettingReady($b->driver);

        $this->assertTrue($b->fresh()->gettingReadyConfirmed());
        $this->assertNotNull($b->fresh()->gettingReadyConfirmedAt());
    }

    public function test_setting_off_counts_as_confirmed_without_a_tap(): void
    {
        $b = $this->job(BookingStatus::EnRoute, now()->addMinutes(5));

        // Already on the way — the strongest possible confirmation.
        $this->assertTrue($b->gettingReadyConfirmed());
    }

    /* ── Driver nudge ────────────────────────────────────────────────────── */

    public function test_it_nudges_the_driver_to_confirm_in_the_prompt_window(): void
    {
        config(['cet.getting_ready.prompt_minutes' => 30]);
        // Pickup 25 min out → inside the 30-min prompt window, still >10 min away.
        $b = $this->job(BookingStatus::Allocated, now()->addMinutes(25));

        $this->tick();

        $this->assertSame(1, JobNudge::where('booking_id', $b->id)->where('nudge_type', 'get_ready')->count());
    }

    public function test_it_does_not_nudge_once_the_driver_has_confirmed(): void
    {
        config(['cet.getting_ready.prompt_minutes' => 30]);
        $b = $this->job(BookingStatus::Allocated, now()->addMinutes(25));
        $b->confirmGettingReady($b->driver);

        $this->tick();

        $this->assertSame(0, JobNudge::where('booking_id', $b->id)->where('nudge_type', 'get_ready')->count());
    }

    /* ── Escalation ──────────────────────────────────────────────────────── */

    public function test_unconfirmed_past_the_escalate_window_alerts_the_office_and_calls(): void
    {
        // Escalate 45 min out, so at pickup+40-away the "not confirmed" gate has
        // passed while the set-off deadline (flat-30 lead → pickup−25) has NOT —
        // isolating the checkpoint as the sole trigger.
        config([
            'cet.getting_ready.prompt_minutes' => 60,
            'cet.getting_ready.escalate_minutes' => 45,
            'services.twilio.sid' => 'AC', 'services.twilio.token' => 'tok',
            'cet.alert_call_from' => '+441111111111', 'cet.office_call_number' => '+449999999999',
        ]);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'CA1'], 201)]);

        $b = $this->job(BookingStatus::Allocated, now()->addMinutes(40));

        $this->tick();

        // Office alerted, worded for the missed checkpoint (not "set off").
        $this->assertSame(1, JobNudge::where('booking_id', $b->id)->where('nudge_type', 'admin_at_risk')->count());
        $this->assertDatabaseHas('watchdog_events', ['booking_id' => $b->id, 'event_type' => 'admin_at_risk']);
        $this->assertTrue(
            \App\Models\WatchdogEvent::where('booking_id', $b->id)->where('title', 'like', '%not confirmed%')->exists()
        );
        // And the emergency auto-call went out.
        $this->assertSame(1, JobNudge::where('booking_id', $b->id)->where('nudge_type', 'office_call_at_risk')->count());
        Http::assertSent(fn ($r) => str_contains($r->url(), '/Calls.json'));
    }

    public function test_confirming_holds_off_the_checkpoint_escalation(): void
    {
        config([
            'cet.getting_ready.prompt_minutes' => 60,
            'cet.getting_ready.escalate_minutes' => 45,
            'services.twilio.sid' => 'AC', 'services.twilio.token' => 'tok',
            'cet.alert_call_from' => '+441111111111', 'cet.office_call_number' => '+449999999999',
        ]);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'CA1'], 201)]);

        $b = $this->job(BookingStatus::Allocated, now()->addMinutes(40));
        $b->confirmGettingReady($b->driver);

        $this->tick();

        // Confirmed → the checkpoint gate doesn't fire, and set-off isn't overdue
        // yet, so nothing escalates and no call goes out.
        $this->assertSame(0, JobNudge::where('booking_id', $b->id)->where('nudge_type', 'admin_at_risk')->count());
        $this->assertSame(0, JobNudge::where('booking_id', $b->id)->where('nudge_type', 'office_call_at_risk')->count());
        Http::assertNothingSent();
    }

    /* ── HTTP endpoints ──────────────────────────────────────────────────── */

    public function test_the_driver_can_confirm_from_the_job_screen(): void
    {
        $driver = $this->driver();
        $b = $this->job(BookingStatus::Allocated, now()->addMinutes(25), $driver);

        $this->actingAs($driver)
            ->post(route('driver.job.on-it', $b))
            ->assertRedirect();

        $this->assertTrue($b->fresh()->gettingReadyConfirmed());
    }

    public function test_a_driver_cannot_confirm_someone_elses_job(): void
    {
        $b = $this->job(BookingStatus::Allocated, now()->addMinutes(25));
        $other = $this->driver();

        $this->actingAs($other)
            ->post(route('driver.job.on-it', $b))
            ->assertForbidden();

        $this->assertFalse($b->fresh()->gettingReadyConfirmed());
    }
}
