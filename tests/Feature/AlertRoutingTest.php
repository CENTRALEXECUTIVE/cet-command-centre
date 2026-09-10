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
            // Backup-routing tests need the full rollout (everyone, backup on).
            'cet.checkpoint.only_emails' => [], 'cet.checkpoint.route_to_backup' => true,
        ]);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'CA1'], 201)]);

        $this->abdi = User::factory()->admin()->create(['phone' => '+447000000001']);
        $this->maj = User::factory()->admin()->create(['phone' => '+447000000002']);
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

    /* ── Pilot scope: Abdi-only, never hands off to the other director ─────── */

    public function test_pilot_mode_only_rings_the_scoped_driver_never_the_backup(): void
    {
        config(['cet.checkpoint.only_emails' => [$this->abdi->email], 'cet.checkpoint.route_to_backup' => false]);
        $this->atRiskJobFor($this->abdi);

        $this->artisan('cet:status-watchdog')->assertSuccessful(); // call 1 → Abdi
        $this->redial();                                            // call 2 → STILL Abdi

        Http::assertSent(fn ($r) => str_contains($r->url(), '/Calls.json') && ($r->data()['To'] ?? '') === $this->abdi->phone);
        // Never rings Maj (the other director) while piloting.
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/Calls.json') && ($r->data()['To'] ?? '') === $this->maj->phone);
    }

    public function test_pilot_mode_ignores_a_job_for_a_driver_out_of_scope(): void
    {
        config(['cet.checkpoint.only_emails' => [$this->abdi->email], 'cet.checkpoint.route_to_backup' => false]);
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
        config(['cet.checkpoint.only_emails' => [$this->abdi->email], 'cet.checkpoint.route_to_backup' => false]);
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

    public function test_a_hold_on_any_pilot_account_silences_the_pilot(): void
    {
        // Abdi's DRIVER record and his LOGIN can be different accounts (both in
        // scope). Holding alerts on the login must still silence a job assigned to
        // the driver account.
        $driverAcct = User::factory()->admin()->create(['email' => 'abdi@centralexecutivetransfers.co.uk', 'phone' => '+447000000001']);
        $loginAcct = User::factory()->admin()->create(['email' => 'admin@centralexecutivetransfers.co.uk']);
        config([
            'cet.checkpoint.only_emails' => ['abdi@centralexecutivetransfers.co.uk', 'admin@centralexecutivetransfers.co.uk'],
            'cet.checkpoint.route_to_backup' => false,
        ]);
        $loginAcct->holdAlertsFor(120); // held on the login, not the driver record

        Booking::factory()->create([
            'driver_id' => $driverAcct->id, 'status' => BookingStatus::Allocated->value, 'pickup_at' => now()->addMinutes(20),
        ]);

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
