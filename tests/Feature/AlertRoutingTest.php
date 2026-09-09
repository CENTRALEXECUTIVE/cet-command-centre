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

    public function test_the_call_goes_to_the_other_free_director(): void
    {
        $this->atRiskJobFor($this->abdi);

        $this->artisan('cet:status-watchdog')->assertSuccessful();

        // Rang Maj (free), not Abdi (the driver who forgot).
        Http::assertSent(fn ($r) => str_contains($r->url(), '/Calls.json') && ($r->data()['To'] ?? '') === $this->maj->phone);
    }

    public function test_it_skips_a_director_who_flagged_themselves_busy(): void
    {
        $this->maj->holdAlertsFor(120); // Maj out on a cover job
        $this->atRiskJobFor($this->abdi);

        $this->artisan('cet:status-watchdog')->assertSuccessful();

        // Maj busy + Abdi is the driver → nobody free → business line fallback.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/Calls.json') && ($r->data()['To'] ?? '') === '+449999999999');
    }

    public function test_it_skips_a_director_already_on_an_active_job(): void
    {
        // Maj is en route on another job → busy → call falls back to the business line.
        Booking::factory()->create(['driver_id' => $this->maj->id, 'status' => BookingStatus::EnRoute->value, 'pickup_at' => now()->subMinutes(5)]);
        $this->atRiskJobFor($this->abdi);

        $this->artisan('cet:status-watchdog')->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/Calls.json') && ($r->data()['To'] ?? '') === '+449999999999');
    }

    public function test_the_hold_toggle_sets_and_clears(): void
    {
        $this->actingAs($this->abdi)->post(route('alerts.hold'))->assertRedirect();
        $this->assertTrue($this->abdi->fresh()->alertsHeld());

        $this->actingAs($this->abdi)->post(route('alerts.hold'))->assertRedirect();
        $this->assertFalse($this->abdi->fresh()->alertsHeld());
    }
}
