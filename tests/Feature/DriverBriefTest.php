<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\CalendarEventBuilder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The office → driver brief: the calendar block minus the price, the booking
 * reference and the customer's real number (masked CET line instead).
 */
class DriverBriefTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
        config([
            'services.twilio_masking.customer_line' => '+447700159929',
            'services.twilio_masking.driver_line' => '+447576574083',
        ]);
    }

    private function brief(array $overrides = []): string
    {
        $customer = Customer::factory()->create(['name' => 'Dr Karl Walton', 'phone' => '07855601980']);
        $driver = User::factory()->driver()->create(['phone' => '07999000111']);
        DriverProfile::create(['user_id' => $driver->id, 'is_third_party' => true]);

        $booking = Booking::factory()->forVehicleType(VehicleType::where('slug', 'executive')->first())->create(array_merge([
            'customer_id' => $customer->id,
            'driver_id' => $driver->id,
            'status' => BookingStatus::Allocated->value,
            'external_reference' => 'U31G0Xa',
            'payment_status' => 'paid',
            'passengers' => 2,
            'flight_number' => 'AFR1169',
            'meta' => ['payment_text' => 'Paid £110 (Square)', 'lead_name' => 'Dr Karl Walton'],
        ], $overrides));

        return app(CalendarEventBuilder::class)->driverBrief($booking->fresh(['customer', 'driver', 'vehicleType']));
    }

    public function test_brief_shows_the_masked_line_not_the_real_number(): void
    {
        $brief = $this->brief();

        $this->assertStringContainsString('+447576574083', $brief);   // the CET driver line
        $this->assertStringNotContainsString('07855601980', $brief);  // customer's real number
        $this->assertStringNotContainsString('+447855601980', $brief);
    }

    public function test_brief_hides_the_price_and_reference(): void
    {
        $brief = $this->brief();

        $this->assertStringContainsString('*Payment:* Paid', $brief);
        $this->assertStringNotContainsString('£110', $brief);        // no amount
        $this->assertStringNotContainsString('U31G0Xa', $brief);     // no booking reference
        $this->assertStringNotContainsString('Booking Reference', $brief);
    }

    public function test_brief_keeps_the_job_details_the_driver_needs(): void
    {
        $brief = $this->brief();

        $this->assertStringContainsString('Dr Karl Walton', $brief);
        $this->assertStringContainsString('AFR1169', $brief);
        $this->assertStringContainsString('Passengers', $brief);
    }

    public function test_cash_job_shows_the_amount_to_collect(): void
    {
        // The driver needs to know how much cash to take — show it.
        $brief = $this->brief(['payment_status' => 'pending', 'meta' => ['payment_text' => 'Cash £90']]);

        $this->assertStringContainsString('£90 to collect', $brief);
    }

    public function test_cash_job_shows_only_the_balance_not_the_deposit(): void
    {
        // Deposit already paid → the driver only sees what's left to collect.
        $brief = $this->brief([
            'payment_status' => 'pending',
            'meta' => ['payment_text' => 'Deposit £10 Paid – £110 Cash Due'],
        ]);

        $this->assertStringContainsString('£110 to collect', $brief);
        $this->assertStringNotContainsString('£10', $brief); // no deposit amount
    }
}
