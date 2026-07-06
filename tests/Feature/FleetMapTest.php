<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\DriverLocation;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetMapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function driverOnJob(string $callsign): User
    {
        $driver = User::factory()->create(['role' => 'driver', 'name' => 'Full Name']);
        DriverProfile::create(['user_id' => $driver->id, 'callsign' => $callsign]);
        $exec = VehicleType::where('slug', 'executive')->first();
        $customer = Customer::create(['name' => 'On Board Cust', 'phone' => '07700900020']);

        Booking::create([
            'reference' => Booking::generateReference(), 'customer_id' => $customer->id,
            'vehicle_type_id' => $exec->id, 'driver_id' => $driver->id,
            'pickup_at' => now()->subMinutes(20), 'pickup_address' => 'A', 'destination_address' => 'Manchester Airport',
            'passengers' => 1, 'status' => 'en_route', 'payment_method' => 'card',
        ]);
        DriverLocation::create([
            'driver_id' => $driver->id, 'latitude' => 53.4001, 'longitude' => -1.4700, 'captured_at' => now(),
        ]);

        return $driver;
    }

    public function test_positions_endpoint_lists_active_drivers_with_callsign(): void
    {
        $admin = User::factory()->admin()->create();
        $this->driverOnJob('Abdi');

        $res = $this->actingAs($admin)->getJson(route('fleet.positions'))->assertOk();
        $res->assertJsonPath('drivers.0.driver', 'Abdi');
        $res->assertJsonPath('drivers.0.status', 'En Route');
        $res->assertJsonPath('drivers.0.destination', 'Manchester Airport');
    }

    public function test_driver_without_a_ping_is_not_shown(): void
    {
        $admin = User::factory()->admin()->create();
        // Driver on a job but no GPS ping yet.
        $driver = User::factory()->create(['role' => 'driver']);
        $exec = VehicleType::where('slug', 'executive')->first();
        Booking::create([
            'reference' => Booking::generateReference(), 'customer_id' => Customer::create(['name' => 'X', 'phone' => '07700900021'])->id,
            'vehicle_type_id' => $exec->id, 'driver_id' => $driver->id,
            'pickup_at' => now(), 'pickup_address' => 'A', 'destination_address' => 'B',
            'passengers' => 1, 'status' => 'accepted', 'payment_method' => 'card',
        ]);

        $this->actingAs($admin)->getJson(route('fleet.positions'))
            ->assertOk()->assertJsonCount(0, 'drivers');
    }

    public function test_map_page_renders_for_admin_only(): void
    {
        $admin = User::factory()->admin()->create();
        $this->driverOnJob('Maj');
        $this->actingAs($admin)->get(route('fleet.map'))->assertOk()->assertSee('Live Map');

        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->get(route('fleet.map'))->assertForbidden();
        $this->actingAs($driver)->getJson(route('fleet.positions'))->assertForbidden();
    }
}
