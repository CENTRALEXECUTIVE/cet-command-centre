<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The Kidderminster case (CET-007A2C): paid £151.20 online on a VAT invoice, then the
 * office re-priced the job to £180 + VAT (= £216). Only £64.80 is still owed — the
 * £151.20 already taken must stay counted, and an open balance link must NEVER be
 * ticked "paid" by the booking page when no money has come in for it.
 */
class RepriceAfterPaymentBalanceTest extends TestCase
{
    use RefreshDatabase;

    private function paidVatBooking(): Booking
    {
        $c = Customer::create(['name' => 'Dane Limosnero', 'email' => 'd@example.com', 'phone' => '07624460475']);

        return Booking::factory()->create([
            'customer_id' => $c->id, 'source' => 'web', 'payment_method' => 'card', 'payment_status' => 'paid',
            'quoted_price' => 126, 'pickup_at' => now()->addDays(10),
            'meta' => ['vat_invoice_requested' => true, 'billing_entity' => 'transfers', 'vat_paid' => true, // the old webhook ticked this — must not count the VAT twice
                'square_payment' => ['id' => 'sq_1', 'amount' => 151.20, 'entity' => 'transfers', 'at' => now()->subDay()->toIso8601String()]],
        ]);
    }

    public function test_reprice_to_180_plus_vat_leaves_64_80_due_through_the_whole_office_flow(): void
    {
        Mail::fake();
        Http::fake();
        $admin = User::factory()->admin()->create();
        $b = $this->paidVatBooking();

        // 1) The office sets the job price to £180 (+ VAT = £216).
        $this->actingAs($admin)->post(route('bookings.price', $b), ['final_price' => 180])->assertRedirect();
        $this->assertDue($b, 'after re-pricing');

        // 2) The office opens the booking page (self-heals run on view).
        $this->actingAs($admin)->get(route('bookings.show', $b))->assertOk();
        $this->assertDue($b, 'after viewing the booking');

        // 3) The office adds the £64.80 balance as a card line (link to send).
        $this->actingAs($admin)->post(route('bookings.transactions.store', $b), [
            'name' => 'Balance', 'amount' => '64.80', 'method' => 'card', 'status' => 'pending',
        ])->assertRedirect();
        $this->assertDue($b, 'after adding the balance line');

        // 4) And views the page again — the open £64.80 line must stay unpaid.
        $this->actingAs($admin)->get(route('bookings.show', $b))->assertOk();
        $this->assertDue($b, 'after viewing again');
        $balance = $b->fresh()->payments()->where('amount', 64.80)->first();
        $this->assertNotNull($balance);
        $this->assertNotSame('paid', $balance->status, 'the open £64.80 balance must NOT be ticked paid — no money has come in');
    }

    public function test_same_when_the_card_link_is_created_from_the_one_tap_button(): void
    {
        Mail::fake();
        Http::fake(['*' => Http::response(['id' => 'x', 'url' => 'https://pay.example/x', 'payment_link' => ['id' => 'pl', 'url' => 'https://pay.example/x']], 200)]);
        $admin = User::factory()->admin()->create();
        $b = $this->paidVatBooking();

        $this->actingAs($admin)->post(route('bookings.price', $b), ['final_price' => 180])->assertRedirect();
        // The "Create card link (WhatsApp / copy)" button posts the amount due as a pending line.
        $this->actingAs($admin)->post(route('bookings.transactions.store', $b), [
            'name' => 'Balance', 'amount' => '64.80', 'method' => 'card', 'status' => 'pending', 'create_link' => 'copy',
        ])->assertRedirect();
        $this->actingAs($admin)->get(route('bookings.show', $b))->assertOk();
        $this->assertDue($b, 'after the one-tap link');
    }

    private function assertDue(Booking $b, string $when): void
    {
        $f = $b->fresh();
        $this->assertEqualsWithDelta(216.0, (float) $f->amountPayable(), 0.01, "payable {$when}");
        $this->assertEqualsWithDelta(151.20, (float) $f->transactionsPaidTotal(), 0.01, "paid {$when}");
        $this->assertEqualsWithDelta(64.80, (float) $f->transactionsAmountDue(), 0.01, "due {$when}");
    }
}
