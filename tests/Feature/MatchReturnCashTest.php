<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Matching two existing bookings as a return pair. The OUTBOUND collects the
 * whole round trip's cash (one payment from the customer), whoever drives the
 * return. Driver PAY is per-leg and never changed by matching.
 */
class MatchReturnCashTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function cashJob(string $ref, string $when, float $fare, float $deposit = 0, ?User $driver = null): Booking
    {
        $b = Booking::factory()->create([
            'reference' => $ref,
            'pickup_at' => \Carbon\Carbon::parse($when),
            'quoted_price' => $fare,
            'payment_method' => 'cash',
            'driver_id' => $driver?->id,
        ]);
        if ($deposit > 0) {
            $b->forceFill(['meta' => array_merge($b->meta ?? [], ['deposit' => ['amount' => $deposit, 'paid' => true]])])->save();
        }

        return $b->fresh();
    }

    public function test_outbound_collects_the_whole_round_trip_even_with_different_drivers(): void
    {
        // Emma's real case: outbound £170 (−£50 deposit = £120) + return £180 = £300,
        // collected ALL on the outbound, even though a different driver does the return.
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $out = $this->cashJob('CET-OUT', '2026-10-09 10:00', 170, 50, $alice);
        $ret = $this->cashJob('CET-RET', '2026-10-12 16:00', 180, 0, $bob);

        $out->linkAsReturnPair($ret);

        $this->assertEqualsWithDelta(300.0, $out->fresh()->cashDueToDriver(), 0.01); // 120 + 180
        $this->assertNull($ret->fresh()->cashDueToDriver());                          // return collects nothing
        $this->assertTrue($ret->fresh()->returnLegCollectedOnOutbound());

        // The driver sees just the one total — no outbound/return split.
        $line = $out->fresh()->driverCollectLine();
        $this->assertStringContainsString('£300 to collect (cash)', $line);
        $this->assertStringNotContainsString('outbound', $line);
        $this->assertStringNotContainsString('return', $line);
    }

    public function test_driver_pay_is_unchanged_per_leg(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $out = $this->cashJob('CET-OUT', '2026-10-09 10:00', 170, 50, $alice);
        $ret = $this->cashJob('CET-RET', '2026-10-12 16:00', 180, 0, $bob);
        $out->forceFill(['meta' => array_merge($out->meta ?? [], ['payroll' => ['pay' => 150]])])->save();
        $ret->forceFill(['meta' => array_merge($ret->meta ?? [], ['payroll' => ['pay' => 150]])])->save();

        $out->fresh()->linkAsReturnPair($ret->fresh());

        $this->assertEqualsWithDelta(150.0, $out->fresh()->driverPay(), 0.01);
        $this->assertEqualsWithDelta(150.0, $ret->fresh()->driverPay(), 0.01);
    }

    public function test_admin_can_match_by_reference_from_the_page(): void
    {
        $admin = User::factory()->admin()->create();
        $out = $this->cashJob('CET-OUT', '2026-10-09 10:00', 170, 50);
        $ret = $this->cashJob('CET-RET', '2026-10-12 16:00', 180, 0);

        $this->actingAs($admin)->post(route('bookings.match-return', $out), ['reference' => 'CET-RET'])
            ->assertRedirect();

        $this->assertSame($ret->id, $out->fresh()->linked_booking_id);
        $this->assertTrue($ret->fresh()->is_return_leg);
    }

    public function test_matching_a_card_job_never_becomes_cash(): void
    {
        $kash = User::factory()->create();
        $out = Booking::factory()->create(['reference' => 'CET-CARD', 'pickup_at' => now()->addDay(), 'quoted_price' => 200, 'payment_method' => 'card', 'driver_id' => $kash->id]);
        $ret = $this->cashJob('CET-RET2', now()->addDays(3)->toDateTimeString(), 150, 0, $kash);

        $out->linkAsReturnPair($ret);

        $this->assertNull($out->fresh()->cashDueToDriver());
    }
}
