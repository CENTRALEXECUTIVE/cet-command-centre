<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use App\Models\VehicleType;
use App\Models\Voucher;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin booking form voucher codes — a valid code comes off the customer price
 * the same way the customer/widget flow applies it, and a discounted job leaves
 * driver pay for the office to set by hand (no auto 90% of a reduced fare).
 */
class AdminVoucherBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function storeBooking(User $admin, array $overrides = []): void
    {
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();

        $this->actingAs($admin)->post(route('bookings.store'), array_merge([
            'customer_name' => 'Test Customer', 'customer_phone' => '07700900123',
            'journey_type' => 'one_way',
            'pickup_address' => '12 Test Road, Sheffield', 'destination_address' => 'Manchester Airport',
            'pickup_at' => now()->addDays(2)->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $exec->id, 'passengers' => 2,
            'payment_method' => 'card', 'quoted_price' => 100.00, 'privacy_consent' => '1',
        ], $overrides))->assertRedirect();
    }

    public function test_a_percent_voucher_comes_off_the_customer_price(): void
    {
        $admin = User::factory()->admin()->create();
        Voucher::create(['code' => 'SAVE20', 'type' => 'percent', 'value' => 20, 'is_active' => true]);

        $this->storeBooking($admin, ['voucher' => 'save20']);

        $booking = Booking::latest('id')->first();
        $this->assertSame('80.00', (string) $booking->quoted_price);      // 100 − 20%
        $this->assertSame('SAVE20', $booking->meta['voucher_code']);
        $this->assertEqualsWithDelta(20.0, $booking->meta['discount'], 0.001);
        $this->assertStringContainsString('Discount code: SAVE20', (string) $booking->special_requests);
    }

    public function test_a_discounted_job_leaves_driver_pay_blank_for_the_office(): void
    {
        $admin = User::factory()->admin()->create();
        Voucher::create(['code' => 'TENOFF', 'type' => 'fixed', 'value' => 10, 'is_active' => true]);

        $this->storeBooking($admin, ['voucher' => 'TENOFF']);

        $booking = Booking::latest('id')->first();
        $this->assertSame('90.00', (string) $booking->quoted_price);       // 100 − £10
        // No auto 90% suggestion on a discounted job — office sets it by hand.
        $this->assertNull($booking->suggestedDriverPay());
    }

    public function test_an_invalid_code_is_recorded_but_does_not_change_the_price(): void
    {
        $admin = User::factory()->admin()->create();

        $this->storeBooking($admin, ['voucher' => 'NOPE']);

        $booking = Booking::latest('id')->first();
        $this->assertSame('100.00', (string) $booking->quoted_price);      // unchanged
        $this->assertSame('NOPE', $booking->meta['voucher_code']);
        $this->assertArrayNotHasKey('discount', $booking->meta);           // nothing applied
        $this->assertStringContainsString('not applied', (string) $booking->special_requests);
        // Undiscounted job keeps the normal 90% suggestion.
        $this->assertEqualsWithDelta(90.0, $booking->suggestedDriverPay(), 0.001);
    }

    public function test_an_expired_voucher_does_not_discount(): void
    {
        $admin = User::factory()->admin()->create();
        Voucher::create(['code' => 'OLD', 'type' => 'percent', 'value' => 50, 'is_active' => true,
            'valid_to' => now()->subDay()]);

        $this->storeBooking($admin, ['voucher' => 'OLD']);

        $booking = Booking::latest('id')->first();
        $this->assertSame('100.00', (string) $booking->quoted_price);
        $this->assertArrayNotHasKey('discount', $booking->meta);
    }
}
