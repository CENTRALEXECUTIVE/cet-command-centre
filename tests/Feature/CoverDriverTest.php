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

    public function test_roster_merges_into_an_admin_director_and_keeps_admin_role(): void
    {
        // Abdi is a director: an ADMIN account that also drives (has a profile).
        $abdi = User::factory()->create(['role' => 'admin', 'name' => 'Abdirazak Hassan', 'email' => 'abdi@cet.test']);
        \App\Models\DriverProfile::create(['user_id' => $abdi->id]);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('cover-drivers.sync'))->assertRedirect();

        // The "Abdi" roster entry attached to the admin account — no clone.
        $this->assertEquals(1, User::where('email', 'like', '%abdi%')->orWhere('name', 'like', '%Abdi%')->count());
        $this->assertEquals('admin', $abdi->fresh()->role->value); // still an admin
        $this->assertEquals('Abdi', $abdi->fresh()->driverProfile->callsign);
        $this->assertEquals('LR69 HHP', $abdi->fresh()->driverProfile->defaultVehicle->registration);
    }

    public function test_driver_cannot_access_the_directory(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->get(route('cover-drivers.index'))->assertForbidden();
    }

    public function test_added_driver_gets_a_plus44_number_and_is_assignable(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('cover-drivers.store'), [
            'name' => 'Zayn', 'phone' => '07123 456789', 'vehicle_reg' => 'zy21 abc', 'vehicle' => 'Black Mercedes S Class',
        ])->assertRedirect();

        $cover = CoverDriver::where('name', 'Zayn')->first();
        $this->assertEquals('+44 7123 456789', $cover->phone);

        // A real, assignable driver account was created and linked.
        $this->assertNotNull($cover->user_id);
        $user = User::find($cover->user_id);
        $this->assertEquals('driver', $user->role->value);
        $this->assertTrue($user->driverProfile()->exists());
        $this->assertEquals('ZY21 ABC', $user->driverProfile->defaultVehicle->registration);
    }

    public function test_sync_makes_the_whole_roster_assignable(): void
    {
        $admin = User::factory()->admin()->create();
        $before = User::where('role', 'driver')->count();

        $this->actingAs($admin)->post(route('cover-drivers.sync'))->assertRedirect();

        $this->assertEquals(0, CoverDriver::whereNull('user_id')->count());
        $this->assertGreaterThan($before, User::where('role', 'driver')->count());
        $this->assertStringStartsWith('+44', CoverDriver::where('name', 'Kash')->value('phone'));
    }

    public function test_roster_reuses_an_existing_matching_driver_instead_of_duplicating(): void
    {
        // A rotation driver already exists whose login local-part is "abdi".
        $abdi = User::factory()->create(['role' => 'driver', 'name' => 'Abdirazak Hassan', 'email' => 'abdi@cet.test']);
        \App\Models\DriverProfile::create(['user_id' => $abdi->id]);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('cover-drivers.sync'))->assertRedirect();

        // The "Abdi" roster entry attaches to the existing account — no duplicate.
        $this->assertEquals(1, User::where('name', 'like', '%Abdi%')->orWhere('email', 'like', 'abdi%')->count());
        $cover = CoverDriver::where('name', 'Abdi')->first();
        $this->assertEquals($abdi->id, $cover->user_id);
        // Its callsign + vehicle are set from the roster.
        $this->assertEquals('Abdi', $abdi->fresh()->driverProfile->callsign);
    }
}
