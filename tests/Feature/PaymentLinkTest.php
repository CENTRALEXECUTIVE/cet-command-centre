<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Creating & sending a Square card payment link from the booking payment panel —
 * by WhatsApp, SMS, email or copy, and in one tap when adding the charge.
 */
class PaymentLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
        // Main Square account configured → both billing entities are enabled.
        config([
            'services.square.access_token' => 'sq-token',
            'services.square.location_id' => 'LOC1',
            'services.square.environment' => 'production',
        ]);
        Http::fake([
            'connect.squareup.com/*' => Http::response(['payment_link' => ['url' => 'https://square.link/pay/ABC']], 200),
        ]);
    }

    private function booking(): Booking
    {
        return Booking::factory()->forVehicleType(VehicleType::where('slug', 'executive')->first())->create([
            'customer_id' => Customer::create(['name' => 'Pay Test', 'phone' => '07700900123', 'email' => 'pay@test.com'])->id,
        ]);
    }

    public function test_whatsapp_channel_creates_a_link_and_gives_a_wa_deep_link(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->booking();
        $payment = $booking->payments()->create(['method' => 'card', 'amount' => 120, 'status' => 'pending']);

        $this->actingAs($admin)
            ->post(route('bookings.transactions.send-link', [$booking, $payment]), ['channel' => 'whatsapp'])
            ->assertRedirect()
            ->assertSessionHas('share_wa')
            ->assertSessionHas('copy_link', 'https://square.link/pay/ABC');

        $this->assertSame('https://square.link/pay/ABC', $payment->fresh()->tide_payment_link);
        $this->assertSame('link_sent', $payment->fresh()->status);
    }

    public function test_copy_channel_just_creates_the_link(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->booking();
        $payment = $booking->payments()->create(['method' => 'card', 'amount' => 80, 'status' => 'pending']);

        $this->actingAs($admin)
            ->post(route('bookings.transactions.send-link', [$booking, $payment]), ['channel' => 'copy'])
            ->assertRedirect()->assertSessionHas('copy_link', 'https://square.link/pay/ABC');
    }

    public function test_add_and_create_link_in_one_step(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->booking();

        $this->actingAs($admin)->post(route('bookings.transactions.store', $booking), [
            'name' => 'Balance', 'amount' => '95', 'method' => 'card', 'status' => 'pending',
            'create_link' => 'whatsapp',
        ])->assertRedirect()->assertSessionHas('copy_link', 'https://square.link/pay/ABC');

        $this->assertSame('link_sent', $booking->payments()->latest('id')->first()->status);
    }

    public function test_link_needs_an_amount(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->booking();
        $payment = $booking->payments()->create(['method' => 'card', 'amount' => 0, 'status' => 'pending']);

        $this->actingAs($admin)
            ->post(route('bookings.transactions.send-link', [$booking, $payment]), ['channel' => 'whatsapp'])
            ->assertRedirect()->assertSessionHas('error');
    }
}
