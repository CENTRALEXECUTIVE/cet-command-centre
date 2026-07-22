<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_todays_booked_value_and_quick_actions(): void
    {
        $admin = User::factory()->admin()->create();
        $exec = VehicleType::where('slug', 'executive')->first();
        $customer = Customer::create(['name' => 'C', 'phone' => '07000000000']);

        Booking::create([
            'reference' => Booking::generateReference(), 'customer_id' => $customer->id,
            'vehicle_type_id' => $exec->id, 'pickup_at' => today()->addHours(12),
            'pickup_address' => 'A', 'destination_address' => 'B', 'passengers' => 1,
            'status' => 'pending', 'payment_method' => 'card', 'final_price' => 120,
        ]);

        $res = $this->actingAs($admin)->get(route('dashboard'))->assertOk();
        $this->assertEquals(120.0, $res->viewData('todayRevenue'));
        $res->assertSee('Today\'s booked value', false)
            ->assertSee(route('quotes.create'))       // quick actions
            ->assertSee('↻ Refresh', false);          // interactive refresh
    }

    public function test_bookings_and_revenue_taken_today(): void
    {
        $admin = User::factory()->admin()->create();
        $exec = VehicleType::where('slug', 'executive')->first();
        $customer = Customer::create(['name' => 'C', 'phone' => '07000000001']);

        $make = fn (array $attrs) => Booking::create(array_merge([
            'reference' => Booking::generateReference(), 'customer_id' => $customer->id,
            'vehicle_type_id' => $exec->id, 'pickup_address' => 'A', 'destination_address' => 'B',
            'passengers' => 1, 'status' => 'pending', 'payment_method' => 'card',
        ], $attrs));

        // Came in TODAY, travelling next week → counts as taken today.
        $make(['pickup_at' => today()->addDays(7), 'final_price' => 200]);

        // Came in YESTERDAY, travelling today → NOT taken today (created earlier).
        $old = $make(['pickup_at' => today()->addHours(9), 'final_price' => 999]);
        Booking::where('id', $old->id)->update(['created_at' => now()->subDay()]);

        // Came in today but cancelled → excluded from the money figure.
        $make(['pickup_at' => today()->addDays(2), 'final_price' => 50, 'status' => BookingStatus::Cancelled->value]);

        $res = $this->actingAs($admin)->get(route('dashboard'))->assertOk();

        $this->assertSame(1, $res->viewData('bookedTodayCount'));
        $this->assertEquals(200.0, $res->viewData('bookedTodayRevenue'));
        $res->assertSee('Bookings taken today', false)->assertSee('Revenue taken today', false);
    }

    public function test_compliance_alert_shows_a_blocked_driver(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->create(['role' => 'driver', 'name' => 'Lapsed Larry']);
        $vehicle = Vehicle::create([
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->first()->id,
            'registration' => 'AB12 CDE', 'make' => 'M', 'model' => 'E', 'colour' => 'Black', 'year' => 2022,
            'is_active' => true, 'mot_expiry' => now()->subWeek(), // expired
        ]);
        DriverProfile::create(['user_id' => $driver->id, 'is_third_party' => false, 'default_vehicle_id' => $vehicle->id]);

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('blocked', false)
            ->assertSee('Lapsed Larry');
    }
}
