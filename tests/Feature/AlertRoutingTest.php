<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The emergency at-risk call must reach whoever's FREE — never blare next to a
 * passenger. It rings the OTHER director's mobile, skips a director who's busy or
 * on an active job, and falls back to the business line when nobody's free.
 */
class AlertRoutingTest extends TestCase
{
    use RefreshDatabase;

    private User $abdi;

    private User $maj;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-15 12:00:00');
        config([
            'services.twilio.sid' => 'AC', 'services.twilio.token' => 'tok',
            'cet.alert_call_from' => '+441111111111', 'cet.office_call_number' => '+449999999999',
            // Backup-routing tests need the full rollout (everyone, backup + call on).
            'cet.checkpoint.scope' => 'all', 'cet.checkpoint.route_to_backup' => true,
            'cet.checkpoint.emergency_call' => true, 'cet.checkpoint.call_scope' => 'all',
            // These tests validate the driver-first-then-backup routing specifically.
            'cet.checkpoint.call_target' => 'driver',
        ]);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'CA1'], 201)]);

        // Abdi is the SUPER ADMIN (the pilot targets super admins); Maj is a
        // regular admin, so scope 'super_admins' = Abdi only.
        $this->abdi = User::factory()->admin()->create(['phone' => '+447000000001', 'is_super_admin' => true]);
        $this->maj = User::factory()->admin()->create(['phone' => '+447000000002', 'is_super_admin' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** An at-risk job assigned to Abdi, pickup 20 min out, no GPS → flat-30 lead. */
    private function atRiskJobFor(User $driver): Booking
    {
        return Booking::factory()->create([
            'driver_id' => $driver->id,
            'status' => BookingStatus::Allocated->value,
            'pickup_at' => now()->addMinutes(20),
        ]);
    }

    /** Advance past the redial interval and run the watchdog again. */
    private function redial(): void
    {
        Carbon::setTestNow(now()->addMinutes(3));
        $this->artisan('cet:status-watchdog')->assertSuccessful();
    }

    public function test_the_first_call_rings_the_driver_who_forgot(): void
    {
        $this->atRiskJobFor($this->abdi);

        $this->artisan('cet:status-watchdog')->assertSuccessful();

        // First call goes to Abdi (the assigned driver) — wake them on their phone.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/Calls.json') && ($r->data()['To'] ?? '') === $this->abdi->phone);
    }

    public function test_the_backup_call_goes_to_the_other_free_director(): void
    {
        $this->atRiskJobFor($this->abdi);

        $this->artisan('cet:status-watchdog')->assertSuccessful(); // call 1 → Abdi (driver)
        $this->redial();                                            // call 2 → backup

        // Backup rang Maj (free), never Abdi again.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/Calls.json') && ($r->data()['To'] ?? '') === $this->maj->phone);
    }

    public function test_the_backup_skips_a_director_who_flagged_themselves_busy(): void
    {
        $this->maj->holdAlertsFor(120); // Maj out on a cover job
        $this->atRiskJobFor($this->abdi);

        $this->artisan('cet:status-watchdog')->assertSuccessful(); // call 1 → Abdi (driver)
        $this->redial();                                            // call 2 → backup

        // Maj busy + Abdi is the driver → nobody free → business line fallback.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/Calls.json') && ($r->data()['To'] ?? '') === '+449999999999');
    }

    public function test_the_backup_skips_a_director_already_on_an_active_job(): void
    {
        // Maj is en route on another job → busy → backup falls back to the business line.
        Booking::factory()->create(['driver_id' => $this->maj->id, 'status' => BookingStatus::EnRoute->value, 'pickup_at' => now()->subMinutes(5)]);
        $this->atRiskJobFor($this->abdi);

        $this->artisan('cet:status-watchdog')->assertSuccessful(); // call 1 → Abdi (driver)
        $this->redial();                                            // call 2 → backup

        Http::assertSent(fn ($r) => str_contains($r->url(), '/Calls.json') && ($r->data()['To'] ?? '') === '+449999999999');
    }

    public function test_a_uk_local_driver_number_is_normalised_and_rung_first(): void
    {
        // Saved as a UK "07…" number — must still be dialled (E.164) as the FIRST
        // call, not silently fall through to the backup.
        $local = User::factory()->driver()->create(['phone' => '07534283126']);
        $this->atRiskJobFor($local);

        $this->artisan('cet:status-watchdog')->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/Calls.json') && ($r->data()['To'] ?? '') === '+447534283126');
    }

    public function test_a_driver_with_no_number_falls_straight_to_backup(): void
    {
        $noPhone = User::factory()->driver()->create(['phone' => null]);
        $this->atRiskJobFor($noPhone);

        $this->artisan('cet:status-watchdog')->assertSuccessful();

        // No driver number to ring → the very first call routes to a free director.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/Calls.json')
            && in_array($r->data()['To'] ?? '', [$this->abdi->phone, $this->maj->phone], true));
    }

    public function test_the_emergency_call_only_rings_for_a_driver_in_the_call_scope(): void
    {
        // The phone call is scoped to Abdi's own login. His job rings; another
        // driver's job still raises the office AT RISK alert but places NO call.
        config(['cet.checkpoint.call_scope' => $this->abdi->email]);

        $this->atRiskJobFor($this->abdi);
        $this->artisan('cet:status-watchdog')->assertSuccessful();
        Http::assertSent(fn ($r) => str_contains($r->url(), '/Calls.json'));
        $this->assertSame(1, \App\Models\JobNudge::where('nudge_type', 'office_call_at_risk')->count());

        // A different driver's at-risk job → office alerted, but no call placed.
        $other = User::factory()->driver()->create(['phone' => '+447000000009']);
        $this->atRiskJobFor($other);
        $this->artisan('cet:status-watchdog')->assertSuccessful();

        $this->assertSame(1, \App\Models\JobNudge::where('nudge_type', 'office_call_at_risk')->count()); // still just Abdi's
        $this->assertTrue(\App\Models\JobNudge::where('nudge_type', 'admin_at_risk')->exists());          // office WAS alerted
    }

    /* ── Pilot scope: Abdi-only, never hands off to the other director ─────── */

    public function test_pilot_mode_only_rings_the_scoped_driver_never_the_backup(): void
    {
        config(['cet.checkpoint.scope' => 'super_admins', 'cet.checkpoint.route_to_backup' => false]);
        $this->atRiskJobFor($this->abdi);

        $this->artisan('cet:status-watchdog')->assertSuccessful(); // call 1 → Abdi
        $this->redial();                                            // call 2 → STILL Abdi

        Http::assertSent(fn ($r) => str_contains($r->url(), '/Calls.json') && ($r->data()['To'] ?? '') === $this->abdi->phone);
        // Never rings Maj (the other director) while piloting.
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/Calls.json') && ($r->data()['To'] ?? '') === $this->maj->phone);
    }

    public function test_pilot_mode_ignores_a_job_for_a_driver_out_of_scope(): void
    {
        config(['cet.checkpoint.scope' => 'super_admins', 'cet.checkpoint.route_to_backup' => false]);
        // Maj's own job is out of scope → no escalation, no call at all.
        $this->atRiskJobFor($this->maj);

        $this->artisan('cet:status-watchdog')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, \App\Models\JobNudge::where('nudge_type', 'office_call_at_risk')->count());
        $this->assertSame(0, \App\Models\JobNudge::where('nudge_type', 'admin_at_risk')->count());
    }

    /* ── Never alert a driver who's on a job ──────────────────────────────── */

    public function test_a_driver_on_a_job_is_never_alerted_in_pilot_mode(): void
    {
        config(['cet.checkpoint.scope' => 'super_admins', 'cet.checkpoint.route_to_backup' => false]);
        // Abdi is mid-job (passenger in the car); a second job of his goes at-risk.
        Booking::factory()->create(['driver_id' => $this->abdi->id, 'status' => BookingStatus::EnRoute->value, 'pickup_at' => now()->subMinutes(5)]);
        $this->atRiskJobFor($this->abdi);

        $this->artisan('cet:status-watchdog')->assertSuccessful();

        // Silence — no blaring call and no critical alert while he's driving.
        Http::assertNothingSent();
        $this->assertSame(0, \App\Models\JobNudge::where('nudge_type', 'office_call_at_risk')->count());
        $this->assertSame(0, \App\Models\JobNudge::where('nudge_type', 'admin_at_risk')->count());
    }

    public function test_a_busy_driver_is_skipped_but_the_backup_is_still_alerted_on_rollout(): void
    {
        // Rollout mode (setUp: everyone, backup on). Abdi is mid-job; his other job
        // goes at-risk → the FREE director (Maj) is rung, never the busy Abdi.
        Booking::factory()->create(['driver_id' => $this->abdi->id, 'status' => BookingStatus::EnRoute->value, 'pickup_at' => now()->subMinutes(5)]);
        $this->atRiskJobFor($this->abdi);

        $this->artisan('cet:status-watchdog')->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/Calls.json') && ($r->data()['To'] ?? '') === $this->maj->phone);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/Calls.json') && ($r->data()['To'] ?? '') === $this->abdi->phone);
    }

    public function test_holding_alerts_silences_the_pilot(): void
    {
        // Abdi (super admin, one combined account) taps "hold my alerts" — his
        // at-risk job then makes no sound at all.
        config(['cet.checkpoint.scope' => 'super_admins', 'cet.checkpoint.route_to_backup' => false]);
        $this->abdi->holdAlertsFor(120);
        $this->atRiskJobFor($this->abdi);

        $this->artisan('cet:status-watchdog')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, \App\Models\JobNudge::where('nudge_type', 'office_call_at_risk')->count());
        $this->assertSame(0, \App\Models\JobNudge::where('nudge_type', 'admin_at_risk')->count());
    }

    public function test_the_hold_toggle_sets_and_clears(): void
    {
        $this->actingAs($this->abdi)->post(route('alerts.hold'))->assertRedirect();
        $this->assertTrue($this->abdi->fresh()->alertsHeld());

        $this->actingAs($this->abdi)->post(route('alerts.hold'))->assertRedirect();
        $this->assertFalse($this->abdi->fresh()->alertsHeld());
    }
}
