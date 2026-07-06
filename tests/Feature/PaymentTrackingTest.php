<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function booking(array $attrs): Booking
    {
        $exec = VehicleType::where('slug', 'executive')->first();
        $customer = Customer::create(['name' => 'Pay Test', 'phone' => '07700900010']);

        return Booking::create(array_merge([
            'reference' => Booking::generateReference(), 'customer_id' => $customer->id,
            'vehicle_type_id' => $exec->id, 'pickup_address' => 'A', 'destination_address' => 'B',
            'passengers' => 1, 'status' => 'complete',
        ], $attrs));
    }

    public function test_owed_and_to_collect_are_separated_correctly(): void
    {
        $admin = User::factory()->admin()->create();

        // Owed: ran, card, not paid.
        $owed = $this->booking(['pickup_at' => now()->subDay(), 'payment_method' => 'card', 'payment_status' => 'pending', 'final_price' => 150]);
        // Upcoming cash to collect.
        $cash = $this->booking(['pickup_at' => now()->addDay(), 'payment_method' => 'cash', 'payment_status' => 'pending', 'quoted_price' => 90, 'status' => 'pending']);
        // Past cash job = collected on the day → NOT outstanding.
        $this->booking(['pickup_at' => now()->subDay(), 'payment_method' => 'cash', 'payment_status' => 'pending', 'final_price' => 60]);
        // Already paid → excluded.
        $this->booking(['pickup_at' => now()->subDay(), 'payment_method' => 'card', 'payment_status' => 'paid', 'final_price' => 200]);

        $res = $this->actingAs($admin)->get(route('payments.index'))->assertOk();
        $this->assertEquals(150.0, $res->viewData('owedTotal'));
        $this->assertEquals(90.0, $res->viewData('collectTotal'));
        $this->assertTrue($res->viewData('owed')->contains($owed));
        $this->assertTrue($res->viewData('toCollect')->contains($cash));
    }

    public function test_admin_can_mark_a_booking_paid(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->booking(['pickup_at' => now()->subDay(), 'payment_method' => 'card', 'payment_status' => 'pending', 'final_price' => 120]);
        $booking->payments()->create(['method' => 'card', 'amount' => 120, 'status' => 'link_sent']);

        $this->actingAs($admin)->post(route('payments.paid', $booking))->assertRedirect();

        $this->assertEquals('paid', $booking->fresh()->payment_status);
        $this->assertEquals('paid', $booking->payments()->first()->status);
        $this->assertNotNull($booking->payments()->first()->paid_at);
    }

    public function test_driver_cannot_view_payments_or_mark_paid(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $booking = $this->booking(['pickup_at' => now()->subDay(), 'payment_method' => 'card', 'payment_status' => 'pending']);

        $this->actingAs($driver)->get(route('payments.index'))->assertForbidden();
        $this->actingAs($driver)->post(route('payments.paid', $booking))->assertForbidden();
    }
}
