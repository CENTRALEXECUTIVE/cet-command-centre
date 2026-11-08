<?php

namespace Tests\Feature;

use App\Mail\PaymentLinkMail;
use App\Models\Booking;
use App\Models\Customer;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The emailed card link says what the money is: a part payment is a DEPOSIT with
 * the rest explained (cash to the driver, or a later link), a payment that clears
 * the fare is the full amount or "the balance", and the journey is always shown.
 */
class PaymentLinkEmailWordingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function booking(string $method = 'cash'): Booking
    {
        return Booking::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'payment_method' => $method,
            'quoted_price' => 105,
            'final_price' => 105,
            'pickup_at' => now()->addDays(3)->setTime(9, 30),
            'pickup_address' => 'Ranmoor Crescent, Sheffield S10',
            'destination_address' => 'Manchester Airport (MAN)',
        ]);
    }

    public function test_a_part_payment_on_a_cash_job_is_a_deposit_with_cash_to_the_driver(): void
    {
        $b = $this->booking('cash');
        $html = (new PaymentLinkMail($b, 10.0, 'https://pay.example/dep'))->render();

        $this->assertStringContainsString('£10.00 deposit', $html);
        $this->assertStringContainsString('The remaining <strong>£95.00</strong> is paid in cash to your driver on the day', $html);
        $this->assertStringNotContainsString('pay the balance', $html);
        $this->assertStringContainsString('Manchester Airport (MAN)', $html);
        $this->assertStringContainsString('Ranmoor Crescent, Sheffield S10', $html);
        $this->assertStringContainsString('Your journey', $html);
    }

    public function test_a_part_payment_on_a_card_job_says_a_link_follows_for_the_rest(): void
    {
        $b = $this->booking('card');
        $html = (new PaymentLinkMail($b, 10.0, 'https://pay.example/dep'))->render();

        $this->assertStringContainsString('£10.00 deposit', $html);
        $this->assertStringContainsString('is due before your journey', $html);
        $this->assertStringNotContainsString('cash to your driver', $html);
    }

    public function test_paying_the_whole_fare_is_not_called_a_deposit_or_a_balance(): void
    {
        $b = $this->booking('card');
        $html = (new PaymentLinkMail($b, 105.0, 'https://pay.example/full'))->render();

        $this->assertStringNotContainsString('deposit', strtolower(strip_tags($html)));
        $this->assertStringNotContainsString('pay the balance', $html);
        $this->assertStringContainsString('please pay for it below by card', $html);
    }

    public function test_paying_what_is_left_after_a_deposit_is_the_balance(): void
    {
        $b = $this->booking('card');
        $b->payments()->create(['method' => 'card', 'amount' => 10, 'status' => 'paid']);
        $html = (new PaymentLinkMail($b->fresh(), 95.0, 'https://pay.example/bal'))->render();

        $this->assertStringContainsString('pay the balance below', $html);
        $this->assertStringNotContainsString('remaining', strtolower($html));
    }
}
