<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * When the office corrects a job's price, everything that follows the fare must
 * update at once — the driver's income, and the customer's pay link.
 */
class PriceChangeCascadeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
        \App\Models\Setting::set('driver_pay_percent', '90', 'integer', 'payroll');
    }

    private function execBooking(float $price): Booking
    {
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();

        return Booking::factory()->create([
            'vehicle_type_id' => $exec->id,
            'payment_method' => 'card',
            'payment_status' => 'pending',
            'quoted_price' => $price,
        ]);
    }

    public function test_correcting_the_price_recomputes_the_auto_driver_pay(): void
    {
        $booking = $this->execBooking(115);
        $booking->applyDefaultDriverPay();                 // auto 90% of £115 = £103.50
        $this->assertSame(103.50, $booking->fresh()->driverPay());

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)
            ->post(route('bookings.price', $booking), ['final_price' => 1])
            ->assertRedirect();

        // Driver income now follows the £1 fare: 90% = £0.90.
        $this->assertSame(0.90, $booking->fresh()->driverPay());
    }

    public function test_a_hand_set_driver_pay_is_not_overwritten_by_a_price_change(): void
    {
        $booking = $this->execBooking(115);
        $admin = User::factory()->admin()->create();

        // Office hand-sets the driver's pay (e.g. an agreed cover price).
        $this->actingAs($admin)->post(route('bookings.payroll', $booking), [
            'action' => 'set', 'amount' => 80,
        ])->assertRedirect();
        $this->assertSame(80.0, $booking->fresh()->driverPay());

        // Correcting the price must NOT touch that agreed figure.
        $this->actingAs($admin)->post(route('bookings.price', $booking), ['final_price' => 1])->assertRedirect();
        $this->assertSame(80.0, $booking->fresh()->driverPay());
    }

    public function test_the_customer_pay_link_is_a_stable_signed_cet_link(): void
    {
        // A pay link built for an email must be our signed widget.pay route, so a
        // later price change is always reflected when the customer clicks.
        $booking = $this->execBooking(115);
        $link = URL::signedRoute('widget.pay', ['booking' => $booking->id]);

        $this->assertStringContainsString('/widget/pay/'.$booking->id, $link);
        $this->assertStringContainsString('signature=', $link);
    }

    public function test_clicking_the_pay_link_on_a_paid_booking_shows_already_paid(): void
    {
        $booking = $this->execBooking(115);
        $booking->forceFill(['payment_status' => 'paid'])->save();

        $this->get(URL::signedRoute('widget.pay', ['booking' => $booking->id]))
            ->assertRedirect(route('widget.paid', ['already' => 1]));
    }
}
