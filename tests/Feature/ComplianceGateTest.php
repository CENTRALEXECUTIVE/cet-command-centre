<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\Compliance\DriverComplianceService;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComplianceGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function driver(array $profile = [], array $vehicle = []): User
    {
        $user = User::factory()->create(['role' => 'driver']);
        $v = Vehicle::create(array_merge([
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->first()->id,
            'registration' => 'AB12 CDE', 'make' => 'Mercedes', 'model' => 'E-Class',
            'colour' => 'Black', 'year' => 2022, 'is_active' => true,
            'mot_expiry' => now()->addYear(), 'insurance_expiry' => now()->addYear(),
            'phv_licence_expiry' => now()->addYear(),
        ], $vehicle));
        DriverProfile::create(array_merge([
            'user_id' => $user->id, 'is_third_party' => false, 'default_vehicle_id' => $v->id,
            'phv_badge_expiry' => now()->addYear(), 'dbs_expiry' => now()->addYear(),
            'driving_licence_expiry' => now()->addYears(3),
        ], $profile));

        return $user;
    }

    private function booking(): Booking
    {
        return Booking::create([
            'reference' => Booking::generateReference(),
            'customer_id' => Customer::create(['name' => 'C', 'phone' => '07000000000'])->id,
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->first()->id,
            'pickup_at' => now()->addDay(), 'pickup_address' => 'A', 'destination_address' => 'B',
            'passengers' => 1, 'status' => 'pending', 'payment_method' => 'card',
        ]);
    }

    public function test_compliant_driver_passes(): void
    {
        $this->assertTrue(app(DriverComplianceService::class)->isCompliant($this->driver()));
    }

    public function test_expired_mot_blocks_with_reason(): void
    {
        $driver = $this->driver(vehicle: ['mot_expiry' => now()->subDays(3)]);
        $svc = app(DriverComplianceService::class);

        $this->assertFalse($svc->isCompliant($driver));
        $this->assertStringContainsString('MOT expired', $svc->blockReason($driver));
    }

    public function test_despatch_allocate_is_blocked_for_expired_driver(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = $this->driver(profile: ['phv_badge_expiry' => now()->subWeek()]);
        $booking = $this->booking();

        $this->actingAs($admin)
            ->from(route('despatch.board'))
            ->post(route('despatch.allocate', $booking), ['driver_id' => $driver->id])
            ->assertSessionHasErrors('driver_id');

        $this->assertNull($booking->fresh()->driver_id); // not allocated
    }

    public function test_despatch_allocate_succeeds_for_compliant_driver(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = $this->driver();
        $booking = $this->booking();

        $this->actingAs($admin)
            ->post(route('despatch.allocate', $booking), ['driver_id' => $driver->id])
            ->assertSessionHasNoErrors();

        $this->assertEquals($driver->id, $booking->fresh()->driver_id);
    }

    public function test_missing_dates_do_not_hard_block(): void
    {
        // A driver with no expiry dates at all (data gap) is not hard-blocked.
        $user = User::factory()->create(['role' => 'driver']);
        DriverProfile::create(['user_id' => $user->id, 'is_third_party' => false]);

        $this->assertTrue(app(DriverComplianceService::class)->isCompliant($user));
    }
}
