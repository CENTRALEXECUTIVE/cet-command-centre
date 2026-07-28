<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\DriverProfile;
use App\Models\Message;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverAppFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
        $this->driver = User::factory()->driver()->create();
        DriverProfile::create(['user_id' => $this->driver->id, 'is_third_party' => true]);
    }

    private function job(BookingStatus $status, array $extra = []): Booking
    {
        $customer = Customer::factory()->create(['phone' => '07700900700']);

        return Booking::factory()->forVehicleType(VehicleType::where('slug', 'executive')->first())->create(array_merge([
            'customer_id' => $customer->id, 'driver_id' => $this->driver->id,
            'status' => $status->value, 'pickup_at' => now()->addHour(),
        ], $extra));
    }

    public function test_arrived_status_notifies_customer(): void
    {
        $job = $this->job(BookingStatus::EnRoute);

        $this->actingAs($this->driver)->post(route('driver.job.status', $job), ['status' => 'arrived'])->assertRedirect();

        $this->assertEquals(BookingStatus::Arrived, $job->fresh()->status);
        $this->assertDatabaseHas('messages', ['booking_id' => $job->id, 'to_address' => '07700900700']);
        $this->assertTrue(Message::where('booking_id', $job->id)->where('body', 'like', '%has arrived%')->exists());
    }

    public function test_full_icabbi_flow_arrived_pob_completed(): void
    {
        $job = $this->job(BookingStatus::Accepted);
        $post = fn ($s) => $this->actingAs($this->driver)->post(route('driver.job.status', $job), ['status' => $s]);

        $post('en_route')->assertRedirect();
        $post('arrived')->assertRedirect();
        $post('collected')->assertRedirect(); // POB
        $post('complete')->assertRedirect();

        $this->assertEquals(BookingStatus::Complete, $job->fresh()->status);
    }

    public function test_cannot_skip_arrived(): void
    {
        $job = $this->job(BookingStatus::EnRoute);

        // En Route → On Board (collected) directly is no longer allowed.
        $this->actingAs($this->driver)->post(route('driver.job.status', $job), ['status' => 'collected'])
            ->assertSessionHasErrors('status');
        $this->assertEquals(BookingStatus::EnRoute, $job->fresh()->status);
    }

    public function test_driver_can_decline_an_offered_job(): void
    {
        $job = $this->job(BookingStatus::Allocated);

        $this->actingAs($this->driver)->post(route('driver.job.decline', $job))->assertRedirect();

        $job->refresh();
        $this->assertEquals(BookingStatus::Pending, $job->status);
        $this->assertNull($job->driver_id);
    }

    public function test_navigate_links_render_on_job_page(): void
    {
        $job = $this->job(BookingStatus::Accepted, ['pickup_address' => 'Manchester Airport']);

        $this->actingAs($this->driver)->get(route('driver.job', $job))
            ->assertOk()
            ->assertSee('Waze to pickup')
            ->assertSee('waze.com/ul', false)          // Waze deep link
            ->assertSee('js-copy-addr', false)          // copy-address buttons
            ->assertSee('google.com/maps/dir', false);  // Google fallback still there
    }

    public function test_earnings_page_totals_the_drivers_pay_not_the_fares(): void
    {
        // Fares £100 + £50, but the driver is PAID £40 + £30 (set by the office)
        // — the earnings page shows their pay, never the customer fares.
        $a = $this->job(BookingStatus::Complete, ['final_price' => 100, 'pickup_at' => today()->addHours(2)]);
        $b = $this->job(BookingStatus::Complete, ['final_price' => 50, 'pickup_at' => today()->addHours(4)]);
        $a->forceFill(['meta' => ['payroll' => ['pay' => 40, 'paid' => 0, 'history' => []]]])->save();
        $b->forceFill(['meta' => ['payroll' => ['pay' => 30, 'paid' => 0, 'history' => []]]])->save();

        $this->actingAs($this->driver)->get(route('driver.earnings'))
            ->assertOk()
            ->assertSee('£70.00')
            ->assertDontSee('£150.00');
    }

    public function test_offers_count_endpoint(): void
    {
        $this->job(BookingStatus::Allocated);
        $this->job(BookingStatus::Allocated);

        $this->actingAs($this->driver)->getJson(route('driver.offers-count'))
            ->assertOk()->assertJson(['offers' => 2]);
    }
}
