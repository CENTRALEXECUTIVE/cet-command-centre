<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Airport;
use App\Models\Booking;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\AirportSeeder;
use Database\Seeders\DirectorSeeder;
use Database\Seeders\RotationSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DespatchBoardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([VehicleTypeSeeder::class, DirectorSeeder::class, AirportSeeder::class, RotationSeeder::class]);
    }

    private function executive(): VehicleType
    {
        return VehicleType::where('slug', 'executive')->first();
    }

    private function pendingBooking(array $overrides = []): Booking
    {
        return Booking::factory()->forVehicleType($this->executive())->create(array_merge([
            'status' => BookingStatus::Pending,
            'driver_id' => null,
            'airport_id' => Airport::where('code', 'LHR')->value('id'),
        ], $overrides));
    }

    public function test_admin_can_view_the_board(): void
    {
        $admin = User::factory()->admin()->create();
        $this->pendingBooking();

        $this->actingAs($admin)->get(route('despatch.board'))->assertOk()->assertSee('Dispatch Board');
    }

    public function test_driver_dropdown_shows_the_vehicle_reg_in_brackets(): void
    {
        $admin = User::factory()->admin()->create();
        $this->pendingBooking(['pickup_at' => today()->addHours(12)]);

        // Give a driver a default vehicle so their reg shows in the picker.
        $driver = User::factory()->create(['role' => 'driver', 'name' => 'Kash', 'is_active' => true]);
        $vehicle = \App\Models\Vehicle::create([
            'vehicle_type_id' => $this->executive()->id, 'registration' => 'AM64 FAR', 'is_active' => true,
        ]);
        \App\Models\DriverProfile::create(['user_id' => $driver->id, 'default_vehicle_id' => $vehicle->id]);

        $this->actingAs($admin)->get(route('despatch.board'))->assertOk()->assertSee("Kash (AM64 FAR)");
    }

    public function test_non_admin_cannot_view_the_board(): void
    {
        $driver = User::factory()->driver()->create();
        $this->actingAs($driver)->get(route('despatch.board'))->assertForbidden();
    }

    public function test_admin_can_allocate_a_driver_with_one_tap(): void
    {
        $admin = User::factory()->admin()->create();
        $abdi = User::where('email', 'abdi@centralexecutivetransfers.co.uk')->first();
        $booking = $this->pendingBooking();

        $this->actingAs($admin)
            ->post(route('despatch.allocate', $booking), ['driver_id' => $abdi->id])
            ->assertRedirect();

        $booking->refresh();
        $this->assertEquals($abdi->id, $booking->driver_id);
        $this->assertEquals(BookingStatus::Allocated, $booking->status);
        $this->assertDatabaseHas('booking_status_histories', [
            'booking_id' => $booking->id, 'to_status' => 'allocated', 'changed_by' => $admin->id,
        ]);
    }

    public function test_admin_can_auto_allocate_via_rotation(): void
    {
        $admin = User::factory()->admin()->create();
        $abdi = User::where('email', 'abdi@centralexecutivetransfers.co.uk')->first();
        $booking = $this->pendingBooking(); // LHR → next is ABDI

        $this->actingAs($admin)->post(route('despatch.auto-allocate', $booking))->assertRedirect();

        $booking->refresh();
        $this->assertEquals($abdi->id, $booking->driver_id);
        $this->assertEquals(BookingStatus::Allocated, $booking->status);
    }

    public function test_admin_can_advance_status(): void
    {
        $admin = User::factory()->admin()->create();
        $abdi = User::where('email', 'abdi@centralexecutivetransfers.co.uk')->first();
        $booking = $this->pendingBooking(['status' => BookingStatus::Allocated, 'driver_id' => $abdi->id]);

        $this->actingAs($admin)
            ->post(route('despatch.status', $booking), ['status' => 'accepted'])
            ->assertRedirect();

        $this->assertEquals(BookingStatus::Accepted, $booking->refresh()->status);
    }

    public function test_illegal_transition_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->pendingBooking(); // pending

        $this->actingAs($admin)
            ->post(route('despatch.status', $booking), ['status' => 'complete'])
            ->assertSessionHasErrors('status');

        $this->assertEquals(BookingStatus::Pending, $booking->refresh()->status);
    }
}
