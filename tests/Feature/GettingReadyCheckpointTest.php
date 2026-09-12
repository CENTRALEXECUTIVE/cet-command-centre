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
        // Mechanics tests run in full-rollout mode; the Abdi-only pilot scope is
        // covered explicitly in AlertRoutingTest.
        config(['cet.checkpoint.scope' => 'all', 'cet.checkpoint.route_to_backup' => true, 'cet.checkpoint.emergency_call' => true, 'cet.checkpoint.call_scope' => 'all']);
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

    private function job(BookingStatus $status, Carbon $pickup, ?User $driver = null, ?Carbon $leadTime = null): Booking
    {
        return Booking::factory()->create([
            'driver_id' => ($driver ?? $this->driver())->id,
            'status' => $status,
            'pickup_at' => $pickup,
            'meta' => $leadTime ? ['lead_time' => $leadTime->toIso8601String()] : null,
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

    /* ── Lead time ───────────────────────────────────────────────────────── */

    public function test_lead_time_prefers_the_operator_value_over_the_estimate(): void
    {
        $alarm = now()->addMinutes(50);
        $b = $this->job(BookingStatus::Allocated, now()->addHours(2), leadTime: $alarm);

        $this->assertFalse($b->leadTimeIsAuto());
        $this->assertSame($alarm->toIso8601String(), $b->leadTimeAt()->toIso8601String());
    }

    public function test_lead_time_falls_back_to_the_smart_estimate_when_unset(): void
    {
        // No operator lead time → the watchdog stores the smart set-off estimate,
        // and the job reports it's auto (not operator-set).
        $b = $this->job(BookingStatus::Allocated, now()->addMinutes(40));
        $this->assertTrue($b->leadTimeIsAuto());

        $this->tick();

        $this->assertNotNull($b->fresh()->meta['lead_time_effective'] ?? null);
        $this->assertTrue($b->fresh()->leadTimeIsAuto());
    }

    /* ── Driver nudge ────────────────────────────────────────────────────── */

    public function test_it_nudges_the_driver_from_the_lead_time(): void
    {
        // Alarm time is now, pickup comfortably ahead → prompt the driver now.
        $b = $this->job(BookingStatus::Allocated, now()->addMinutes(40), leadTime: now());

        $this->tick();

        $this->assertSame(1, JobNudge::where('booking_id', $b->id)->where('nudge_type', 'get_ready')->count());
    }

    public function test_it_does_not_nudge_before_the_lead_time(): void
    {
        // Alarm time is 20 min away → don't alert before their alarm.
        $b = $this->job(BookingStatus::Allocated, now()->addMinutes(60), leadTime: now()->addMinutes(20));

        $this->tick();

        $this->assertSame(0, JobNudge::where('booking_id', $b->id)->where('nudge_type', 'get_ready')->count());
    }

    public function test_it_does_not_nudge_once_the_driver_has_confirmed(): void
    {
        $b = $this->job(BookingStatus::Allocated, now()->addMinutes(40), leadTime: now());
        $b->confirmGettingReady($b->driver);

        $this->tick();

        $this->assertSame(0, JobNudge::where('booking_id', $b->id)->where('nudge_type', 'get_ready')->count());
    }

    /* ── Escalation ──────────────────────────────────────────────────────── */

    public function test_unconfirmed_past_the_lead_time_grace_alerts_the_office_and_calls(): void
    {
        config([
            'cet.getting_ready.escalate_grace_minutes' => 5,
            'services.twilio.sid' => 'AC', 'services.twilio.token' => 'tok',
            'cet.alert_call_from' => '+441111111111', 'cet.office_call_number' => '+449999999999',
        ]);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'CA1'], 201)]);

        // Alarm was 10 min ago (grace 5 → escalate 5 min ago), but pickup is 40 min
        // out so the set-off deadline (flat-30 lead) has NOT passed — isolating the
        // missed checkpoint as the sole trigger.
        $b = $this->job(BookingStatus::Allocated, now()->addMinutes(40), leadTime: now()->subMinutes(10));

        $this->tick();

        // Office alerted, worded for the missed checkpoint (not "set off").
        $this->assertSame(1, JobNudge::where('booking_id', $b->id)->where('nudge_type', 'admin_at_risk')->count());
        $this->assertDatabaseHas('watchdog_events', ['booking_id' => $b->id, 'event_type' => 'admin_at_risk']);
        $this->assertTrue(
            \App\Models\WatchdogEvent::where('booking_id', $b->id)->where('title', 'like', '%not confirmed%')->exists()
        );
        // And the emergency auto-call went out — to the BUSINESS LINE (default target).
        $this->assertSame(1, JobNudge::where('booking_id', $b->id)->where('nudge_type', 'office_call_at_risk')->count());
        Http::assertSent(fn ($r) => str_contains($r->url(), '/Calls.json') && ($r->data()['To'] ?? '') === '+449999999999');
    }

    public function test_confirming_holds_off_the_checkpoint_escalation(): void
    {
        config([
            'cet.getting_ready.escalate_grace_minutes' => 5,
            'services.twilio.sid' => 'AC', 'services.twilio.token' => 'tok',
            'cet.alert_call_from' => '+441111111111', 'cet.office_call_number' => '+449999999999',
        ]);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'CA1'], 201)]);

        $b = $this->job(BookingStatus::Allocated, now()->addMinutes(40), leadTime: now()->subMinutes(10));
        $b->confirmGettingReady($b->driver);

        $this->tick();

        // Confirmed → the checkpoint gate doesn't fire, and set-off isn't overdue
        // yet, so nothing escalates and no call goes out.
        $this->assertSame(0, JobNudge::where('booking_id', $b->id)->where('nudge_type', 'admin_at_risk')->count());
        $this->assertSame(0, JobNudge::where('booking_id', $b->id)->where('nudge_type', 'office_call_at_risk')->count());
        Http::assertNothingSent();
    }

    public function test_no_at_risk_before_the_getting_ready_checkin_opens(): void
    {
        config(['cet.getting_ready.escalate_grace_minutes' => 5]);

        // The green check-in opens at the lead time — here 5 min from NOW (future).
        // Even though a raw set-off deadline (flat-30 lead: pickup − 30 = 10 min ago)
        // has passed, the office must NOT be alerted before the driver has even been
        // asked to confirm. (This is the exact bug: AT RISK fired 06:45 while the
        // check-in didn't open until 07:00.)
        $b = $this->job(BookingStatus::Allocated, now()->addMinutes(20), leadTime: now()->addMinutes(5));

        $this->tick();

        $this->assertSame(0, JobNudge::where('booking_id', $b->id)->where('nudge_type', 'admin_at_risk')->count());
        $this->assertSame(0, JobNudge::where('booking_id', $b->id)->where('nudge_type', 'office_call_at_risk')->count());
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

    public function test_an_admin_can_set_and_clear_the_lead_time(): void
    {
        $admin = User::factory()->admin()->create();
        $b = $this->job(BookingStatus::Allocated, now()->addHours(3));

        // Set it (UK-local clock time on the pickup day).
        $this->actingAs($admin)
            ->post(route('bookings.lead-time', $b), ['lead_time' => '2026-07-15T05:30'])
            ->assertRedirect();
        $this->assertFalse($b->fresh()->leadTimeIsAuto());
        $this->assertSame('05:30', $b->fresh()->leadTimeAt()->format('H:i'));

        // Clear it → back to the smart estimate.
        $this->actingAs($admin)
            ->post(route('bookings.lead-time', $b), ['lead_time' => ''])
            ->assertRedirect();
        $this->assertTrue($b->fresh()->leadTimeIsAuto());
    }

    public function test_a_driver_cannot_set_the_lead_time(): void
    {
        $b = $this->job(BookingStatus::Allocated, now()->addHours(3));

        $this->actingAs($this->driver())
            ->post(route('bookings.lead-time', $b), ['lead_time' => '2026-07-15T05:30'])
            ->assertForbidden();
    }

    public function test_the_driver_screen_tells_them_when_the_check_in_opens(): void
    {
        $driver = $this->driver();
        // Lead time 45 min away → before the check-in; show the "opens at" hint,
        // not the button.
        $b = $this->job(BookingStatus::Accepted, now()->addHours(2), $driver, leadTime: now()->addMinutes(45));

        $this->actingAs($driver)->get(route('driver.job', $b))
            ->assertOk()
            ->assertSee('check-in opens at')
            ->assertDontSee('🟢 Getting ready');
    }

    public function test_the_driver_screen_shows_the_button_at_lead_time(): void
    {
        $driver = $this->driver();
        $b = $this->job(BookingStatus::Accepted, now()->addHours(2), $driver, leadTime: now());

        $this->actingAs($driver)->get(route('driver.job', $b))
            ->assertOk()
            ->assertSee('🟢 Getting ready');
    }

    public function test_the_bookings_list_shows_the_lead_time_for_admins(): void
    {
        $admin = User::factory()->admin()->create();
        // A live upcoming job with the alarm set to 12:30.
        $this->job(BookingStatus::Allocated, now()->addHours(2), leadTime: now()->addMinutes(30));

        $this->actingAs($admin)->get(route('bookings.index'))
            ->assertOk()
            ->assertSee('⏰ 12:30');
    }
}
