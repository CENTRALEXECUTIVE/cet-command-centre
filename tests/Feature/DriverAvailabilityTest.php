<?php

namespace Tests\Feature;

use App\Models\DriverProfile;
use App\Models\User;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Per-driver weekly availability — which days a driver normally works.
 */
class DriverAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_admin_can_set_a_drivers_weekly_availability(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->create(['role' => 'driver']);
        $driver->driverProfile()->create(['user_id' => $driver->id]);

        $this->actingAs($admin)->put(route('drivers.update', $driver), [
            'name' => $driver->name, 'email' => $driver->email,
            'available_days' => [1, 2, 3, 4, 5], // Mon–Fri
            'availability_note' => 'mornings only',
        ])->assertRedirect();

        $profile = $driver->driverProfile->fresh();
        $this->assertSame([1, 2, 3, 4, 5], $profile->availableWeekdays());
        $this->assertSame('mornings only', $profile->availability_note);
        $this->assertSame('Mon, Tue, Wed, Thu, Fri', $profile->availabilityLabel());
    }

    public function test_is_available_on_flags_a_known_day_off(): void
    {
        $profile = new DriverProfile(['available_days' => [1, 2, 3, 4, 5]]); // weekdays only
        $this->assertFalse($profile->isAvailableOn(Carbon::parse('2026-10-04'))); // a Sunday
        $this->assertTrue($profile->isAvailableOn(Carbon::parse('2026-10-05')));  // a Monday
    }

    public function test_no_pattern_means_available_any_day(): void
    {
        $profile = new DriverProfile(['available_days' => []]);
        $this->assertTrue($profile->isAvailableOn(Carbon::parse('2026-10-04')));
        $this->assertNull($profile->availabilityLabel());
    }
}
