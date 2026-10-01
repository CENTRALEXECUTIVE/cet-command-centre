<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
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

    public function test_a_driver_can_be_created_without_an_email(): void
    {
        // A cover / third-party driver the office dispatches and messages on their
        // phone needs no login — email can be skipped. The account is still created,
        // with a non-routable placeholder login, and reads as "no app login".
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('drivers.store'), [
            'name' => 'Aamer Hanif', 'phone' => '+447956717922', 'callsign' => 'Aamer',
            'is_third_party' => '1',
            // no email
        ])->assertRedirect()->assertSessionHas('status');

        $driver = User::where('name', 'Aamer Hanif')->first();
        $this->assertNotNull($driver);
        $this->assertFalse($driver->hasAppLogin());
        $this->assertNull($driver->displayEmail());
        $this->assertStringEndsWith('@no-login.cet', $driver->email);
        // Still a usable driver record — profile created, phone kept.
        $this->assertNotNull($driver->driverProfile);
        $this->assertSame('+447956717922', $driver->phone);
    }

    public function test_two_drivers_without_an_email_do_not_clash(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('drivers.store'), ['name' => 'Cover One'])->assertRedirect();
        $this->actingAs($admin)->post(route('drivers.store'), ['name' => 'Cover Two'])->assertRedirect();

        $this->assertNotSame(
            User::where('name', 'Cover One')->value('email'),
            User::where('name', 'Cover Two')->value('email'),
        );
    }

    public function test_re_adding_a_driver_whose_plate_is_on_cet_goes_to_their_record(): void
    {
        // A driver already on CET (their plate is on file) must be RECOGNISED, not
        // added again — no duplicate, and definitely no unique-key 500.
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('drivers.store'), [
            'name' => 'Amer', 'registration' => 'SL22 WCW', 'make' => 'Mercedes', 'model' => 'EQS',
        ])->assertRedirect();
        $owner = User::where('name', 'Amer')->firstOrFail();
        $driversBefore = User::where('role', UserRole::Driver->value)->count();

        // Same plate again (different name, different case) → taken to the existing record.
        $this->actingAs($admin)->post(route('drivers.store'), [
            'name' => 'Aamer Hanif', 'registration' => 'sl22 wcw', 'callsign' => 'Aamer',
        ])->assertRedirect(route('driver-documents.show', $owner))->assertSessionHas('status');

        $this->assertSame($driversBefore, User::where('role', UserRole::Driver->value)->count());
        $this->assertNull(User::where('name', 'Aamer Hanif')->first());
        $this->assertSame(1, Vehicle::where('registration', 'SL22 WCW')->count());
    }

    public function test_adding_a_driver_on_an_orphan_plate_reuses_the_vehicle(): void
    {
        // A plate on file but not assigned to any driver is reused, not duplicated.
        $admin = User::factory()->admin()->create();
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();
        $orphan = Vehicle::create(['vehicle_type_id' => $exec->id, 'registration' => 'OR11 PHN', 'is_active' => true]);

        $this->actingAs($admin)->post(route('drivers.store'), [
            'name' => 'New Driver', 'registration' => 'or11 phn', 'make' => 'Audi',
        ])->assertRedirect();

        $this->assertSame(1, Vehicle::where('registration', 'OR11 PHN')->count());
        $driver = User::where('name', 'New Driver')->firstOrFail();
        $this->assertSame($orphan->id, $driver->driverProfile->default_vehicle_id);
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
            'callsign' => 'Kash',
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
        $this->assertEquals('Kash', $profile->callsign);
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
