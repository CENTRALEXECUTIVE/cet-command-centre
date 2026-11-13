<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Directors, 13 Nov 2026: Abdi/Maj on a Manchester Airport exec job = flat £95, both ways. */
class DirectorManchesterPayTest extends TestCase
{
    use RefreshDatabase;

    private function driver(bool $thirdParty): User
    {
        $u = User::factory()->create(['role' => 'driver']);
        DriverProfile::create(['user_id' => $u->id, 'is_third_party' => $thirdParty]);

        return $u;
    }

    private function job(User $driver, string $from, string $to, string $slug = 'executive', float $fare = 125): Booking
    {
        $vt = VehicleType::where('slug', $slug)->first();
        $c = Customer::create(['name' => 'Pay Test', 'phone' => '07700900444']);

        return Booking::factory()->create([
            'customer_id' => $c->id, 'driver_id' => $driver->id, 'vehicle_type_id' => $vt->id,
            'pickup_address' => $from, 'destination_address' => $to,
            'quoted_price' => $fare, 'pickup_at' => now()->addDay(), 'meta' => [],
        ]);
    }

    public function test_directors_get_95_on_manchester_both_ways(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        $director = $this->driver(false);

        $this->assertSame(95.0, $this->job($director, 'Sheffield S1 2HH', 'Manchester Airport (MAN)')->suggestedDriverPay());
        $this->assertSame(95.0, $this->job($director, 'Manchester Airport (MAN)', 'Sheffield S1 2HH')->suggestedDriverPay());
    }

    public function test_cover_drivers_and_other_airports_keep_the_90_percent(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        $cover = $this->driver(true);
        $director = $this->driver(false);

        $this->assertSame(112.5, $this->job($cover, 'Sheffield S1 2HH', 'Manchester Airport (MAN)')->suggestedDriverPay());
        $this->assertSame(112.5, $this->job($director, 'Sheffield S1 2HH', 'Leeds Bradford Airport (LBA)')->suggestedDriverPay());
    }

    public function test_an_auto_90_percent_figure_switches_to_95_when_a_director_takes_it(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        $director = $this->driver(false);
        $b = $this->job($director, 'Sheffield S1 2HH', 'Manchester Airport (MAN)');
        $b->forceFill(['meta' => ['payroll' => ['pay' => 112.5, 'pay_auto' => true]]])->save();

        $this->assertTrue($b->fresh()->applyDefaultDriverPay());
        $this->assertSame(95.0, $b->fresh()->driverPay());

        // A hand-typed price is never touched.
        $b->forceFill(['meta' => ['payroll' => ['pay' => 80]]])->save();
        $this->assertFalse($b->fresh()->applyDefaultDriverPay());
        $this->assertSame(80.0, $b->fresh()->driverPay());
    }
}
