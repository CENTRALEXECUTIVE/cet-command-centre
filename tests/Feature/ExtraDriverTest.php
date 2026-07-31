<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Multi-car jobs (e.g. a 3-car wedding): the office adds extra drivers, each
 * with their own link and their own per-car status, tracked separately.
 */
class ExtraDriverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function booking(): Booking
    {
        return Booking::factory()
            ->forVehicleType(VehicleType::where('slug', 'executive')->first())
            ->create([
                'customer_id' => Customer::factory()->create(['name' => 'Nick Wedding', 'phone' => '07700900111'])->id,
                'pickup_at' => now()->addDay(),
                'status' => BookingStatus::Allocated->value,
            ]);
    }

    public function test_admin_adds_extra_cars_and_each_gets_its_own_link(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->booking();

        $this->actingAs($admin)
            ->post(route('bookings.extra-drivers.add', $booking), ['name' => 'Sam Jones', 'phone' => '07700900222', 'car' => 'Black V-Class'])
            ->assertRedirect();

        $booking->refresh();
        $this->assertCount(1, $booking->extraDrivers());
        $this->assertSame(2, $booking->carCount());

        $token = $booking->extraDrivers()[0]['token'];
        // The extra-car link opens the job sheet for Car 2.
        $this->get(route('driver.car', $token))->assertOk()
            ->assertSee('Car 2 of 2')
            ->assertSee('Nick Wedding')
            ->assertSee('Accept job'); // first status button for an allocated car
    }

    public function test_extra_car_status_is_tracked_separately_from_the_booking(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->booking();
        $this->actingAs($admin)->post(route('bookings.extra-drivers.add', $booking), ['name' => 'Sam Jones']);
        $token = $booking->fresh()->extraDrivers()[0]['token'];

        // Car 2 driver accepts then sets off — only THIS car's status moves.
        $this->post(route('driver.car.status', $token), ['status' => 'accepted'])->assertRedirect();
        $this->post(route('driver.car.status', $token), ['status' => 'en_route'])->assertRedirect();

        $booking->refresh();
        $this->assertSame('en_route', $booking->extraDrivers()[0]['status']);
        // The main booking status is untouched.
        $this->assertSame(BookingStatus::Allocated, $booking->status);
    }

    public function test_an_illegal_car_step_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->booking();
        $this->actingAs($admin)->post(route('bookings.extra-drivers.add', $booking), ['name' => 'Sam Jones']);
        $token = $booking->fresh()->extraDrivers()[0]['token'];

        // Can't jump allocated → complete.
        $this->post(route('driver.car.status', $token), ['status' => 'complete'])
            ->assertSessionHasErrors('status');
    }

    public function test_admin_can_remove_an_extra_car(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->booking();
        $this->actingAs($admin)->post(route('bookings.extra-drivers.add', $booking), ['name' => 'Sam Jones']);
        $token = $booking->fresh()->extraDrivers()[0]['token'];

        $this->actingAs($admin)->post(route('bookings.extra-drivers.remove', $booking), ['token' => $token])->assertRedirect();
        $this->assertCount(0, $booking->fresh()->extraDrivers());
    }

    public function test_extra_car_endpoints_are_admin_only_for_management(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $booking = $this->booking();
        $this->actingAs($driver)->post(route('bookings.extra-drivers.add', $booking), ['name' => 'X'])->assertForbidden();
    }
}
