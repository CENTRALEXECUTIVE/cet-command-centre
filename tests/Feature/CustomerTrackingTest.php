<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\DriverLocation;
use App\Models\DriverProfile;
use App\Models\Message;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\BookingStatusService;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public customer tracking page: privacy (first name only, no internals),
 * live position only while the job runs, link wind-down on completion, and the
 * passenger-on-board notification task.
 */
class CustomerTrackingTest extends TestCase
{
    use RefreshDatabase;

    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
        $this->driver = User::factory()->driver()->create(['name' => 'Jonathan Smithers']);
        DriverProfile::create(['user_id' => $this->driver->id, 'is_third_party' => true]);
    }

    private function job(BookingStatus $status, array $attrs = []): Booking
    {
        return Booking::factory()
            ->forVehicleType(VehicleType::where('slug', 'executive')->first())
            ->create(array_merge([
                'driver_id' => $this->driver->id,
                'status' => $status,
                'pickup_at' => now()->addHour(),
            ], $attrs));
    }

    public function test_tracking_page_shows_driver_first_name_only(): void
    {
        $job = $this->job(BookingStatus::EnRoute);
        $link = $job->trackingLink()->create(['token' => 'tokpage1', 'expires_at' => now()->addHours(3)]);

        $this->get(route('track', $link->token))
            ->assertOk()
            ->assertSee('Jonathan')          // first name shown
            ->assertDontSee('Smithers')      // surname is private
            ->assertSee('Your driver is on the way');
    }

    public function test_tracking_page_never_shows_payment_or_price(): void
    {
        $job = $this->job(BookingStatus::EnRoute, ['quoted_price' => 350, 'final_price' => 350]);
        $link = $job->trackingLink()->create(['token' => 'tokpage2', 'expires_at' => now()->addHours(3)]);

        $this->get(route('track', $link->token))
            ->assertOk()
            ->assertDontSee('350')
            ->assertDontSee('Payment');
    }

    public function test_location_feed_requires_a_valid_token(): void
    {
        $this->getJson(route('track.location', 'no-such-token'))->assertStatus(410);
        $this->get(route('track', 'no-such-token'))->assertStatus(410);
    }

    public function test_location_is_hidden_once_the_job_is_finished(): void
    {
        $job = $this->job(BookingStatus::Complete);
        $link = $job->trackingLink()->create(['token' => 'tokdone', 'expires_at' => now()->addHours(3)]);
        DriverLocation::create([
            'driver_id' => $this->driver->id, 'booking_id' => $job->id,
            'latitude' => 53.5, 'longitude' => -1.5, 'captured_at' => now(),
        ]);

        // A ping exists, but the journey is over — no position leaves the server.
        $this->getJson(route('track.location', $link->token))
            ->assertOk()
            ->assertJson(['available' => false, 'finished' => true])
            ->assertJsonPath('latitude', null);
    }

    public function test_completing_a_job_winds_the_tracking_link_down(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = Customer::create(['name' => 'Track Customer', 'phone' => '07700900555']);
        $job = $this->job(BookingStatus::Collected, ['customer_id' => $customer->id]);
        $link = $job->trackingLink()->create(['token' => 'tokexpire', 'expires_at' => now()->addDays(3)]);

        app(BookingStatusService::class)->transition($job, BookingStatus::Complete, $admin);

        // The link now expires within ~2 hours instead of days.
        $this->assertTrue($link->fresh()->expires_at->lte(now()->addHours(2)->addMinute()));
    }

    public function test_passenger_on_board_queues_a_customer_notification(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = Customer::create(['name' => 'POB Customer', 'phone' => '07700900666']);
        $job = $this->job(BookingStatus::Arrived, ['customer_id' => $customer->id]);

        app(BookingStatusService::class)->transition($job, BookingStatus::Collected, $admin);

        $msg = Message::where('booking_id', $job->id)
            ->where('body', 'like', '%Passenger on board%')
            ->first();
        $this->assertNotNull($msg, 'POB should create a customer notification task.');
    }

    public function test_fleet_positions_flag_stale_gps(): void
    {
        $admin = User::factory()->admin()->create();
        $job = $this->job(BookingStatus::EnRoute);
        DriverLocation::create([
            'driver_id' => $this->driver->id, 'booking_id' => $job->id,
            'latitude' => 53.4, 'longitude' => -1.4,
            'captured_at' => now()->subMinutes(30), // well past 2× the 5-min interval
        ]);

        $this->actingAs($admin)->getJson(route('fleet.positions'))
            ->assertOk()
            ->assertJsonPath('drivers.0.stale', true);
    }
}
