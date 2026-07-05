<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminDriverManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_admin_creates_a_driver_with_login_profile_and_vehicle(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('drivers.store'), [
            'name' => 'Kash Khan',
            'email' => 'kash@example.com',
            'phone' => '07123456789',
            'password' => 'secret-pass-1',
            'registration' => 'kx19 abc',
            'make' => 'Mercedes',
            'model' => 'V-Class',
            'colour' => 'Black',
            'year' => 2021,
        ])->assertRedirect();

        $driver = User::where('email', 'kash@example.com')->first();
        $this->assertNotNull($driver);
        $this->assertEquals(UserRole::Driver, $driver->role);
        $this->assertTrue(Hash::check('secret-pass-1', $driver->password));
        $this->assertNotNull($driver->driverProfile);

        // Vehicle created, uppercased, and set as the driver's default.
        $vehicle = Vehicle::where('registration', 'KX19 ABC')->first();
        $this->assertNotNull($vehicle);
        $this->assertEquals($vehicle->id, $driver->driverProfile->default_vehicle_id);
    }

    public function test_password_is_auto_generated_when_left_blank(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('drivers.store'), [
            'name' => 'No Password', 'email' => 'nopass@example.com',
        ])->assertRedirect()->assertSessionHas('status');

        $driver = User::where('email', 'nopass@example.com')->first();
        $this->assertNotNull($driver->password); // a password was set
    }

    public function test_non_admin_cannot_create_drivers(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->get(route('drivers.create'))->assertForbidden();
        $this->actingAs($driver)->post(route('drivers.store'), ['name' => 'X', 'email' => 'x@x.com'])->assertForbidden();
    }

    public function test_admin_edits_a_driver(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->create(['role' => 'driver', 'name' => 'Old Name']);
        \App\Models\DriverProfile::create(['user_id' => $driver->id, 'is_third_party' => false]);

        $this->actingAs($admin)->put(route('drivers.update', $driver), [
            'name' => 'New Name', 'email' => $driver->email, 'is_active' => 1,
        ])->assertRedirect();

        $this->assertEquals('New Name', $driver->fresh()->name);
    }

    public function test_admin_edits_driver_compliance_and_vehicle_details(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->create(['role' => 'driver', 'name' => 'Compliance Driver']);
        \App\Models\DriverProfile::create(['user_id' => $driver->id, 'is_third_party' => false]);

        $this->actingAs($admin)->put(route('drivers.update', $driver), [
            'name' => 'Compliance Driver',
            'email' => $driver->email,
            'is_active' => 1,
            // Compliance
            'phv_badge_number' => 'PHV-4471',
            'phv_badge_expiry' => '2027-03-01',
            'driving_licence_number' => 'KHAN901234AB9CD',
            'dbs_status' => 'Enhanced — clear',
            // Vehicle (created on the fly)
            'registration' => 'ab12 cde',
            'make' => 'BMW',
            'model' => '5 Series',
            'colour' => 'Silver',
            'year' => 2022,
            'mot_expiry' => '2026-11-15',
            'insurance_expiry' => '2026-08-20',
        ])->assertRedirect();

        $profile = $driver->fresh()->driverProfile;
        $this->assertEquals('PHV-4471', $profile->phv_badge_number);
        $this->assertEquals('2027-03-01', $profile->phv_badge_expiry->format('Y-m-d'));
        $this->assertEquals('Enhanced — clear', $profile->dbs_status);

        $vehicle = $profile->defaultVehicle;
        $this->assertNotNull($vehicle);
        $this->assertEquals('AB12 CDE', $vehicle->registration);
        $this->assertEquals('BMW', $vehicle->make);
        $this->assertEquals('2026-11-15', $vehicle->mot_expiry->format('Y-m-d'));
    }
}
