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

    public function test_page_shows_the_order_and_up_next(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('rotation.index'))
            ->assertOk()
            ->assertSee('The order')
            ->assertSee('Up next')
            ->assertSee('Abdi')
            ->assertSee('Maj');
    }

    public function test_page_shows_history_after_an_allocation(): void
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

        $this->actingAs($admin)->get(route('rotation.index'))
            ->assertOk()
            ->assertSee('Recent history')
            ->assertSee($booking->reference);
    }

    public function test_non_admin_cannot_view_rotation(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->get(route('rotation.index'))->assertForbidden();
    }
}
