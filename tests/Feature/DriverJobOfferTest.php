<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Offer this job to a driver" — a short brief (vital info + the fare to the
 * driver) the office sends so a driver can take the job. The fare is the
 * driver's payroll pay, so the offer and payroll always match; blank until set.
 */
class DriverJobOfferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function offerJob(array $overrides = []): Booking
    {
        $minibus = VehicleType::where('slug', 'minibus')->first() ?? VehicleType::first();
        $customer = Customer::create(['name' => 'Yasmine Clarke Harris', 'phone' => '07700900123']);

        return Booking::factory()->create(array_merge([
            'customer_id' => $customer->id,
            'vehicle_type_id' => $minibus->id,
            'pickup_at' => '2026-08-11 08:00:00',
            'pickup_address' => '33 Goose Lane, Wickersley, Rotherham S66 1JN',
            'destination_address' => 'Manchester Airport M90 1QX',
            'passengers' => 3,
            'luggage' => 2,
            'special_requests' => 'I have a baby so I will be bringing a car seat and pram',
            'status' => BookingStatus::Pending,
            'payment_method' => PaymentMethod::Cash->value,
            'quoted_price' => 130, 'final_price' => 130,
        ], $overrides));
    }

    public function test_the_offer_message_has_the_vital_info(): void
    {
        $booking = $this->offerJob();
        // Real imported bookings carry the office's luggage wording in meta.
        $booking->forceFill(['meta' => ['luggage_text' => '2 Suitcases', 'payroll' => ['pay' => 130, 'paid' => 0, 'history' => []]]])->save();

        $msg = $booking->fresh()->driverOfferMessage();

        $this->assertStringContainsString('Job Available – 11/08/26', $msg);
        $this->assertStringContainsString('📍 33 Goose Lane, Wickersley, Rotherham S66 1JN → Manchester Airport M90 1QX', $msg);
        $this->assertStringContainsString('🕒 Pickup: 08:00 am', $msg);
        $this->assertStringContainsString('👥 3 Passengers', $msg);
        $this->assertStringContainsString('🧳 2 Suitcases + Pram', $msg);
        $this->assertStringContainsString('👶 Customer has own car seat', $msg);
        $this->assertStringContainsString('💷 Fare to you: £130 Cash', $msg);
    }

    public function test_the_fare_is_blank_until_the_driver_pay_is_set(): void
    {
        $booking = $this->offerJob();

        $this->assertStringContainsString('Fare to you: £____', $booking->driverOfferMessage());

        // Setting the driver's pay fills it in — the two always match.
        $booking->forceFill(['meta' => ['payroll' => ['pay' => 145, 'paid' => 0, 'history' => []]]])->save();
        $this->assertStringContainsString('Fare to you: £145 Cash', $booking->fresh()->driverOfferMessage());
    }

    public function test_a_card_job_shows_bank_transfer_not_cash(): void
    {
        $booking = $this->offerJob([
            'payment_method' => PaymentMethod::Card->value,
            'payment_status' => 'paid',
            'special_requests' => null,
        ]);
        $booking->forceFill(['meta' => ['payment_text' => 'Paid £130 (Stripe)', 'payroll' => ['pay' => 60, 'paid' => 0, 'history' => []]]])->save();

        $this->assertStringContainsString('Fare to you: £60 Bank transfer', $booking->fresh()->driverOfferMessage());
    }

    public function test_the_booking_page_shows_the_offer_card_to_admins(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->offerJob();
        $booking->forceFill(['meta' => ['payroll' => ['pay' => 130, 'paid' => 0, 'history' => []]]])->save();

        $this->actingAs($admin)->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('Offer this job to a driver')
            ->assertSee('Job Available')
            ->assertSee('Fare to you: £130 Cash');
    }

    public function test_the_offer_card_prompts_to_set_the_fare_when_unset(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->offerJob();

        $this->actingAs($admin)->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('Set the fare to the driver')
            ->assertSee('£____');
    }

    public function test_child_seats_use_the_calendar_not_a_wrong_meta_count(): void
    {
        // The reported bug: meta['child_seats'] was 7 (= passengers) but the
        // calendar says "1 Child Seat". The offer must show 1, never 7.
        $booking = $this->offerJob(['passengers' => 7, 'special_requests' => null]);
        $booking->forceFill(['meta' => ['child_seats' => 7, 'payroll' => ['pay' => 130]]])->save();
        $booking->calendarEvents()->create([
            'calendar_id' => 'cal', 'title' => 'x', 'location' => 'x',
            'description' => "• Passengers: 7\n• Child Seats / Booster Seats / Infant Seats: 🚼 1 Child Seat\n• Vehicle Type: Minibus",
            'start_at' => now(), 'end_at' => now()->addHour(), 'timezone' => 'Europe/London',
        ]);

        $msg = $booking->fresh()->driverOfferMessage();
        $this->assertStringContainsString('👶 1 Child Seat', $msg);
        $this->assertStringNotContainsString('7 child seat', $msg);
    }

    public function test_the_driver_link_shows_the_calendar_child_seats_not_bad_meta(): void
    {
        // End-to-end via the real driver link, with the exact calendar wording
        // from the reported booking and a corrupt meta count.
        $booking = $this->offerJob(['passengers' => 7, 'special_requests' => null]);
        $booking->forceFill(['meta' => ['child_seats' => 7]])->save();
        $booking->calendarEvents()->create([
            'calendar_id' => 'cal', 'title' => 'x', 'location' => 'x',
            'description' => "🚼 Booking Confirmation – Arrival\n• Passengers: 7\n• Child Seats / Booster Seats / Infant Seats: 🚼 1 Child Seat\n• Vehicle Type: Minibus",
            'start_at' => now(), 'end_at' => now()->addHour(), 'timezone' => 'Europe/London',
        ]);

        $this->get(route('driver.link', $booking->driverLinkToken()))
            ->assertOk()
            ->assertSee('1 Child Seat')
            ->assertDontSee('7 child seats');
    }

    public function test_a_corrupt_count_with_no_calendar_is_dropped_not_shown(): void
    {
        // A count at/above the passenger total with no calendar to verify is the
        // corruption signature — it's dropped entirely so the driver never sees
        // an impossible number.
        $booking = Booking::factory()->create(['passengers' => 4]);
        $booking->forceFill(['meta' => ['child_seats' => 99]])->save();

        $this->assertNull($booking->fresh()->displayChildSeats());

        // A sensible count with no calendar is still shown as-is.
        $ok = Booking::factory()->create(['passengers' => 6]);
        $ok->forceFill(['meta' => ['child_seats' => 2]])->save();
        $this->assertSame('2 child seats', $ok->fresh()->displayChildSeats());
    }

    public function test_setting_the_fare_from_the_offer_card_updates_the_message(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->offerJob();

        $this->actingAs($admin)
            ->post(route('bookings.payroll', $booking), ['action' => 'set', 'amount' => '130'])
            ->assertRedirect();

        $this->assertSame(130.0, $booking->fresh()->driverPay());
        $this->assertStringContainsString('Fare to you: £130 Cash', $booking->fresh()->driverOfferMessage());
    }
}
