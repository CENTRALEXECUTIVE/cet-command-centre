<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\JobNudge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Guards the SHIPPED config defaults for the at-risk emergency call. The real
 * director driver accounts are abdi@ / maj@ (both super admins) — NOT admin@.
 * A previous default of 'admin@…' meant a director's own at-risk job matched no
 * scope, so the call never fired for them. These tests assert the defaults ring
 * a director's job and route it to the business line, WITHOUT overriding config
 * — so a regression in the defaults fails here.
 */
class EmergencyCallDefaultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-15 12:00:00');
        // Only Twilio credentials + numbers — the routing config is left at the
        // file defaults on purpose (that is what we're guarding).
        config([
            'services.twilio.sid' => 'AC', 'services.twilio.token' => 'tok',
            'cet.alert_call_from' => '+441111111111', 'cet.office_call_number' => '+449999999999',
        ]);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'CA1'], 201)]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_shipped_defaults_scope_the_call_to_super_admins_and_route_to_office(): void
    {
        $this->assertSame('super_admins', config('cet.checkpoint.call_scope'));
        $this->assertSame('abdi@centralexecutivetransfers.co.uk', config('cet.checkpoint.office_call_drivers'));
        $this->assertTrue((bool) config('cet.checkpoint.emergency_call'));
    }

    public function test_a_directors_own_at_risk_job_rings_the_business_line_by_default(): void
    {
        $abdi = User::factory()->admin()->create([
            'email' => 'abdi@centralexecutivetransfers.co.uk',
            'phone' => '+447000000001',
            'is_super_admin' => true,
        ]);

        // Allocated, pickup 20 min out, no GPS → flat-30 lead → already overdue,
        // never tapped "Getting ready" → at risk on the next watchdog run.
        Booking::factory()->create([
            'driver_id' => $abdi->id,
            'status' => BookingStatus::Allocated->value,
            'pickup_at' => now()->addMinutes(20),
        ]);

        $this->artisan('cet:status-watchdog')->assertSuccessful();

        // A call WAS placed, and to the BUSINESS LINE (not Abdi's own phone).
        Http::assertSent(fn ($r) => str_contains($r->url(), '/Calls.json')
            && ($r->data()['To'] ?? '') === '+449999999999');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/Calls.json')
            && ($r->data()['To'] ?? '') === '+447000000001');
        $this->assertSame(1, JobNudge::where('nudge_type', 'office_call_at_risk')->count());
    }
}
