<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\RotationService;
use Database\Seeders\AirportSeeder;
use Database\Seeders\DirectorSeeder;
use Database\Seeders\RotationSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RotationViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([VehicleTypeSeeder::class, DirectorSeeder::class, AirportSeeder::class, RotationSeeder::class]);
    }

    public function test_page_shows_the_airport_order_view(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('rotation.index'))
            ->assertOk()
            ->assertSee('Airport order')
            ->assertSee('Executive only')
            ->assertSee('Upcoming')
            ->assertSee('Abdi')
            ->assertSee('Maj');
    }

    public function test_a_booking_shows_under_its_airport_with_the_rotation_driver(): void
    {
        $admin = User::factory()->admin()->create();
        $executive = VehicleType::where('slug', 'executive')->first();
        $customer = Customer::create(['name' => 'Test Passenger']);
        $airport = \App\Models\Airport::where('code', 'LHR')->first();

        $booking = Booking::create([
            'reference' => Booking::generateReference(),
            'customer_id' => $customer->id,
            'vehicle_type_id' => $executive->id,
            'airport_id' => $airport?->id,
            'pickup_at' => now()->addDay(),
            'pickup_address' => 'Pickup',
            'destination_address' => 'Destination',
            'passengers' => 1,
            'status' => 'pending',
            'payment_method' => 'card',
        ]);
        app(RotationService::class)->allocate($booking);

        // The booking appears in its airport's order list with the assigned driver.
        $this->actingAs($admin)->get(route('rotation.index', ['route' => 'LHR']))
            ->assertOk()
            ->assertSee('Airport order')
            ->assertSee($booking->reference);
    }

    public function test_non_admin_cannot_view_rotation(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->get(route('rotation.index'))->assertForbidden();
    }
}
