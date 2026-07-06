<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CoverDriver;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoverDriverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_roster_is_seeded_by_the_migration(): void
    {
        // The migration seeds the current roster.
        $this->assertTrue(CoverDriver::where('name', 'Kash')->where('vehicle_reg', 'AM64 FAR')->exists());
        $this->assertTrue(CoverDriver::where('name', 'Mansoor')->exists());
        $this->assertGreaterThanOrEqual(10, CoverDriver::count());
    }

    public function test_admin_can_add_a_driver(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('cover-drivers.store'), [
            'name' => 'New Cover', 'phone' => '07000000000', 'vehicle_reg' => 'ab12 cde', 'vehicle' => 'Black Merc',
        ])->assertRedirect();
        $this->assertDatabaseHas('cover_drivers', ['name' => 'New Cover']);
    }

    public function test_booking_page_offers_the_roster_in_the_picker(): void
    {
        $admin = User::factory()->admin()->create();
        $exec = VehicleType::where('slug', 'executive')->first();
        $customer = Customer::create(['name' => 'C', 'phone' => '07700900050']);
        $booking = Booking::create([
            'reference' => Booking::generateReference(), 'customer_id' => $customer->id,
            'vehicle_type_id' => $exec->id, 'pickup_at' => now()->addDay(),
            'pickup_address' => 'A', 'destination_address' => 'B', 'passengers' => 1,
            'status' => 'pending', 'payment_method' => 'card',
        ]);

        $this->actingAs($admin)->get(route('bookings.show', $booking))
            ->assertOk()->assertSee('Kash')->assertSee('AM64 FAR');
    }

    public function test_driver_cannot_access_the_directory(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->get(route('cover-drivers.index'))->assertForbidden();
    }
}
