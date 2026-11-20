<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Nov 2026 (JELD-WEN): a paid V-Class was downgraded to Executive. The page then
 * read "Paid £138" and hid the £72 she'd overpaid, and driver pay stayed at the
 * V-Class figure. Now the overpayment shows, "Refund difference" sends it back
 * (or records a hand refund), and an Edit carries the change into driver pay.
 */
class RefundDifferenceTest extends TestCase
{
    use RefreshDatabase;

    private function paidThenDowngraded(string $paymentId = 'sqpay_123'): Booking
    {
        $b = Booking::factory()->create(['quoted_price' => 175]);
        $b->markFarePaid($paymentId, 175.00, 'transfers');
        $b->forceFill(['quoted_price' => 115, 'final_price' => null])->save();

        return $b->fresh();
    }

    public function test_overpayment_is_shown_not_hidden(): void
    {
        $b = $this->paidThenDowngraded();
        $this->assertSame(60.0, $b->overpaidAmount());
        $this->assertSame(175.0, $b->grossTakenForLeg()); // what she really paid is kept

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('bookings.show', $b))->assertOk()
            ->assertSee('Refund difference: £60.00')->assertSee('Refund to card via Square');
    }

    public function test_refund_to_card_via_square_records_it_and_clears_the_overpayment(): void
    {
        Setting::set('square_access_token', 'sq-token');
        Setting::set('square_location_id', 'LOC1');
        Http::fake(['*/v2/refunds' => Http::response(['refund' => ['id' => 'rf_1', 'status' => 'PENDING']])]);
        $b = $this->paidThenDowngraded();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('bookings.refund-difference', $b), ['amount' => 60, 'mode' => 'send', 'reason' => 'Downgraded to Executive'])
            ->assertRedirect()->assertSessionHas('status');

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v2/refunds')
            && $r['payment_id'] === 'sqpay_123' && $r['amount_money']['amount'] === 6000 && $r['amount_money']['currency'] === 'GBP');
        $b->refresh();
        $this->assertSame(60.0, $b->liveRefundsTotal());
        $this->assertSame('rf_1', $b->liveRefunds()[0]['ref']);
        $this->assertSame(0.0, $b->overpaidAmount());
        $this->assertSame(115.0, $b->transactionsPaidTotal());
    }

    public function test_a_failed_refund_records_nothing(): void
    {
        Setting::set('square_access_token', 'sq-token');
        Setting::set('square_location_id', 'LOC1');
        Http::fake(['*/v2/refunds' => Http::response(['errors' => [['detail' => 'Insufficient funds']]], 400)]);
        $b = $this->paidThenDowngraded();
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.refund-difference', $b), ['amount' => 60, 'mode' => 'send'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'Insufficient funds'));
        $this->assertSame([], $b->fresh()->liveRefunds());
    }

    public function test_cannot_refund_more_than_the_overpayment_and_hand_refunds_move_no_money(): void
    {
        Http::fake();
        $b = $this->paidThenDowngraded();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('bookings.refund-difference', $b), ['amount' => 80, 'mode' => 'record'])->assertSessionHas('error');
        $this->actingAs($admin)->post(route('bookings.refund-difference', $b), ['amount' => 60, 'mode' => 'record'])->assertSessionHas('status');
        Http::assertNothingSent();
        $this->assertSame('manual', $b->fresh()->liveRefunds()[0]['how']);
        $this->assertSame(0.0, $b->fresh()->overpaidAmount());
    }

    public function test_stripe_payments_refund_through_stripe(): void
    {
        Setting::set('stripe_secret_key', 'sk_test_x');
        Http::fake(['api.stripe.com/v1/refunds' => Http::response(['id' => 're_9'])]);
        $b = $this->paidThenDowngraded('pi_abc');
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.refund-difference', $b), ['amount' => 60, 'mode' => 'send'])->assertSessionHas('status');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.stripe.com/v1/refunds') && $r['payment_intent'] === 'pi_abc' && (int) $r['amount'] === 6000);
        $this->assertSame('stripe', $b->fresh()->liveRefunds()[0]['how']);
    }

    public function test_drivers_cannot_refund(): void
    {
        $b = $this->paidThenDowngraded();
        $this->actingAs(User::factory()->driver()->create())
            ->post(route('bookings.refund-difference', $b), ['amount' => 60, 'mode' => 'record'])->assertForbidden();
    }

    public function test_recompute_moves_auto_driver_pay_with_the_new_fare(): void
    {
        $b = Booking::factory()->create(['quoted_price' => 175]);
        $b->forceFill(['meta' => ['payroll' => ['pay' => 157.50, 'pay_auto' => true]]])->save();
        $b->forceFill(['quoted_price' => 115])->save();
        $new = $b->fresh()->recomputeAutoDriverPay(175.0);
        $this->assertNotNull($new);
        $this->assertNotEquals(157.50, $b->fresh()->driverPay());
    }
}
