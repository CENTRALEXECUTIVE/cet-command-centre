<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Matching two existing bookings as a return pair combines the cash on the
 * outbound, with deposits excluded, and the return reads "collect nothing".
 */
class MatchReturnCashTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function cashJob(string $ref, string $when, float $fare, float $deposit = 0): Booking
    {
        $b = Booking::factory()->create([
            'reference' => $ref,
            'pickup_at' => \Carbon\Carbon::parse($when),
            'quoted_price' => $fare,
            'payment_method' => 'cash',
        ]);
        if ($deposit > 0) {
            $b->forceFill(['meta' => array_merge($b->meta ?? [], ['deposit' => ['amount' => $deposit, 'paid' => true]])])->save();
        }

        return $b->fresh();
    }

    public function test_matching_combines_cash_on_the_outbound_with_deposits_excluded(): void
    {
        // Outbound: £350 fare, £50 deposit paid → £300 to collect.
        $out = $this->cashJob('CET-OUT', '2026-10-09 10:00', 350, 50);
        // Return: £300 fare, no deposit → £300.
        $ret = $this->cashJob('CET-RET', '2026-10-12 16:00', 300, 0);

        $out->linkAsReturnPair($ret);
        $out = $out->fresh();
        $ret = $ret->fresh();

        // Outbound collects both: £300 + £300 = £600.
        $this->assertEqualsWithDelta(600.0, $out->cashDueToDriver(), 0.01);
        // The return collects nothing.
        $this->assertNull($ret->cashDueToDriver());
        $this->assertTrue($ret->is_return_leg);
        $this->assertTrue($ret->returnLegCollectedOnOutbound());
    }

    public function test_unlinking_resets_the_figures(): void
    {
        $out = $this->cashJob('CET-OUT', '2026-10-09 10:00', 350, 50);
        $ret = $this->cashJob('CET-RET', '2026-10-12 16:00', 300, 0);
        $out->linkAsReturnPair($ret);

        $out->fresh()->unlinkReturnPair();

        // Each stands alone again: outbound £300 (fare − deposit), return £300.
        $this->assertEqualsWithDelta(300.0, $out->fresh()->cashDueToDriver(), 0.01);
        $this->assertEqualsWithDelta(300.0, $ret->fresh()->cashDueToDriver(), 0.01);
    }

    public function test_admin_can_match_by_reference_from_the_page(): void
    {
        $admin = User::factory()->admin()->create();
        $out = $this->cashJob('CET-OUT', '2026-10-09 10:00', 350, 50);
        $ret = $this->cashJob('CET-RET', '2026-10-12 16:00', 300, 0);

        $this->actingAs($admin)->post(route('bookings.match-return', $out), ['reference' => 'CET-RET'])
            ->assertRedirect();

        $this->assertSame($ret->id, $out->fresh()->linked_booking_id);
        $this->assertEqualsWithDelta(600.0, $out->fresh()->cashDueToDriver(), 0.01);
    }

    public function test_matching_a_card_job_never_becomes_cash(): void
    {
        // Pairing must never turn a card job into a cash collection.
        $out = Booking::factory()->create(['reference' => 'CET-CARD', 'pickup_at' => now()->addDay(), 'quoted_price' => 200, 'payment_method' => 'card']);
        $ret = $this->cashJob('CET-RET2', now()->addDays(3)->toDateTimeString(), 150, 0);

        $out->linkAsReturnPair($ret);

        $this->assertNull($out->fresh()->cashDueToDriver());
    }
}
