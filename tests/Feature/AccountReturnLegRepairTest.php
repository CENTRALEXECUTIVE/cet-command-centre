<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CorporateAccount;
use App\Models\Customer;
use App\Support\AccountReturnLegRepair;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Old account web returns: the unpaid card return leg moves onto the outbound's account. */
class AccountReturnLegRepairTest extends TestCase
{
    use RefreshDatabase;

    private function trip(array $returnMeta = [], string $returnStatus = 'pending'): array
    {
        $account = CorporateAccount::create(['name' => 'MEPS International Ltd', 'account_code' => '1001',
            'slug' => 'meps-'.uniqid(), 'is_active' => true, 'payment_terms_days' => 30]);
        $c = Customer::create(['name' => 'Jayne Craven', 'email' => 'j@meps.co.uk', 'corporate_account_id' => $account->id]);
        $ret = Booking::factory()->create(['customer_id' => $c->id, 'is_return_leg' => true, 'journey_type' => 'return',
            'source' => 'web', 'payment_method' => 'card', 'payment_status' => $returnStatus, 'quoted_price' => 115, 'meta' => $returnMeta]);
        $out = Booking::factory()->create(['customer_id' => $c->id, 'journey_type' => 'return', 'source' => 'web',
            'payment_method' => 'account', 'corporate_account_id' => $account->id, 'linked_booking_id' => $ret->id,
            'quoted_price' => 105, 'meta' => ['account_code' => '1001', 'account_name' => 'MEPS International Ltd']]);

        return [$account, $out, $ret];
    }

    public function test_unpaid_card_return_leg_moves_onto_the_account(): void
    {
        [$account, , $ret] = $this->trip();

        $this->assertSame([$ret->reference], AccountReturnLegRepair::run());

        $f = $ret->fresh();
        $this->assertSame('account', $f->payment_method->value);
        $this->assertSame($account->id, (int) $f->corporate_account_id);
        $this->assertSame('1001', $f->meta['account_code']);
        $this->assertEquals(115, (float) $f->quoted_price, 'price untouched');
        $this->assertSame([], AccountReturnLegRepair::run(), 'second run is a no-op');
    }

    public function test_a_return_leg_already_paid_by_card_is_left_alone(): void
    {
        [, , $ret] = $this->trip(['square_payment' => ['id' => 'sq_1', 'amount' => 138]], 'paid');

        $this->assertSame([], AccountReturnLegRepair::run());
        $this->assertSame('card', $ret->fresh()->payment_method->value);
    }
}
