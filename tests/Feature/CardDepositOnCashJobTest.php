<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A cash job where the customer pays a deposit by card link: the driver must
 * collect only the balance, with no office edit needed (£105 fare, £10 card
 * deposit → collect £95).
 */
class CardDepositOnCashJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function booking(string $method = 'cash', array $meta = []): Booking
    {
        return Booking::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'payment_method' => $method,
            'quoted_price' => 105,
            'final_price' => 105,
            'pickup_at' => now()->addDays(3),
            'pickup_address' => 'Ranmoor Crescent, Sheffield S10',
            'destination_address' => 'Leeds city centre',
            'meta' => $meta,
        ]);
    }

    public function test_a_card_deposit_paid_online_makes_the_driver_collect_the_balance(): void
    {
        $b = $this->booking();
        $this->assertSame(105.0, $b->cashDueToDriver());

        $b->markFarePaid('pi_dep_1', 10.0, 'chauffeurs');
        $b = $b->fresh();

        $this->assertSame(10.0, $b->depositPaidAmount());
        $this->assertSame(95.0, $b->cashDueToDriver());
        $this->assertSame('pending', $b->payment_status);
    }

    public function test_a_card_deposit_line_marked_paid_in_the_ledger_does_the_same(): void
    {
        $b = $this->booking();
        $p = $b->payments()->create(['method' => 'card', 'amount' => 10, 'status' => 'link_sent']);
        $this->assertSame(105.0, $b->fresh()->cashDueToDriver()); // not paid yet → full fare

        $p->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
        $b->fresh()->reconcilePaymentStatusFromTransactions();

        $this->assertSame(95.0, $b->fresh()->cashDueToDriver());
        $this->assertSame(95.0, $b->fresh()->transactionsAmountDue()); // not double-counted
    }

    public function test_an_office_typed_deposit_is_never_changed_only_ticked_paid(): void
    {
        $b = $this->booking('cash', ['deposit' => ['amount' => 15, 'paid' => false]]);
        $b->markFarePaid('pi_dep_2', 20.0, 'chauffeurs');
        $b = $b->fresh();

        $this->assertSame(15.0, $b->depositAmount());
        $this->assertSame(15.0, $b->depositPaidAmount());
        $this->assertSame(90.0, $b->cashDueToDriver());
    }

    public function test_a_cash_job_paid_in_full_by_card_still_collects_nothing(): void
    {
        $b = $this->booking();
        $b->markFarePaid('pi_full_1', 105.0, 'chauffeurs');

        $this->assertNull($b->fresh()->cashDueToDriver());
        $this->assertSame('paid', $b->fresh()->payment_status);
    }

    public function test_a_card_job_is_untouched(): void
    {
        $b = $this->booking('card');
        $b->markFarePaid('pi_card_1', 10.0, 'chauffeurs');

        $this->assertSame(0.0, $b->fresh()->depositAmount());
        $this->assertNull($b->fresh()->cashDueToDriver());
    }

    public function test_the_automatic_deposit_goes_when_the_card_payment_is_reset(): void
    {
        $b = $this->booking();
        $p = $b->payments()->create(['method' => 'card', 'amount' => 10, 'status' => 'paid', 'paid_at' => now()]);
        $b->fresh()->reconcilePaymentStatusFromTransactions();
        $this->assertSame(95.0, $b->fresh()->cashDueToDriver());

        $p->forceFill(['status' => 'refunded', 'paid_at' => null])->save();
        $b->fresh()->reconcilePaymentStatusFromTransactions();

        $this->assertSame(0.0, $b->fresh()->depositAmount());
        $this->assertSame(105.0, $b->fresh()->cashDueToDriver());
    }
}
