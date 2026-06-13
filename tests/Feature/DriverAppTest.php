<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\AirportSeeder;
use Database\Seeders\DirectorSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverAppTest extends TestCase
{
    use RefreshDatabase;

    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([VehicleTypeSeeder::class, DirectorSeeder::class, AirportSeeder::class]);

        $this->driver = User::factory()->driver()->create(['name' => 'Test Driver']);
        DriverProfile::create(['user_id' => $this->driver->id, 'is_third_party' => true, 'is_available' => true]);
    }

    private function jobFor(User $driver, array $overrides = []): Booking
    {
        return Booking::factory()
            ->forVehicleType(VehicleType::where('slug', 'executive')->first())
            ->create(array_merge(['driver_id' => $driver->id], $overrides));
    }

    public function test_driver_sees_only_their_jobs_for_today(): void
    {
        $mine = $this->jobFor($this->driver, ['pickup_at' => today()->addHours(3), 'status' => BookingStatus::Allocated]);
        $someoneElse = $this->jobFor(User::factory()->driver()->create(), ['pickup_at' => today()->addHours(3)]);

        $this->actingAs($this->driver)->get(route('driver.jobs', ['filter' => 'today']))
            ->assertOk()
            ->assertSee($mine->reference)
            ->assertDontSee($someoneElse->reference);
    }

    public function test_driver_can_accept_and_go_en_route_capturing_gps(): void
    {
        $customer = Customer::factory()->create(['phone' => '07700900999']);
        $job = $this->jobFor($this->driver, [
            'customer_id' => $customer->id,
            'status' => BookingStatus::Accepted,
            'pickup_at' => now()->addHours(2),
        ]);

        $this->actingAs($this->driver)
            ->post(route('driver.job.status', $job), ['status' => 'en_route', 'lat' => 53.3811, 'lng' => -1.4701])
            ->assertRedirect();

        $job->refresh();
        $this->assertEquals(BookingStatus::EnRoute, $job->status);

        // GPS captured in the audit trail.
        $this->assertDatabaseHas('booking_status_histories', [
            'booking_id' => $job->id, 'to_status' => 'en_route',
        ]);
        $history = $job->statusHistory()->where('to_status', 'en_route')->first();
        $this->assertEquals('53.3811000', $history->gps_latitude);

        // En Route side effects: tracking link + customer message.
        $this->assertNotNull($job->trackingLink);
        $this->assertDatabaseHas('messages', ['booking_id' => $job->id, 'type' => 'tracking_link']);
    }

    public function test_driver_cannot_update_another_drivers_job(): void
    {
        $job = $this->jobFor(User::factory()->driver()->create(), ['status' => BookingStatus::Accepted]);

        $this->actingAs($this->driver)
            ->post(route('driver.job.status', $job), ['status' => 'en_route'])
            ->assertForbidden();
    }

    public function test_public_tracking_page_renders_via_token(): void
    {
        $customer = Customer::factory()->create(['phone' => '07700900111']);
        $job = $this->jobFor($this->driver, [
            'customer_id' => $customer->id,
            'status' => BookingStatus::Accepted,
            'pickup_at' => now()->addHours(2),
        ]);
        $this->actingAs($this->driver)->post(route('driver.job.status', $job), ['status' => 'en_route']);

        $token = $job->fresh()->trackingLink->token;

        $this->get(route('track', $token))->assertOk()->assertSee('on the way');
    }
}
