<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\AirportSeeder;
use Database\Seeders\DirectorSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([VehicleTypeSeeder::class, DirectorSeeder::class, AirportSeeder::class]);
        config(['services.anthropic.key' => null]); // use the built-in summary, no API call
    }

    public function test_admin_sees_review_with_revenue_vehicle_and_recommendations(): void
    {
        // Pin to mid-month so the "this month" default window is deterministic
        // (a booking 2 days ago can't fall into the previous month).
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-07-15 12:00:00'));

        $admin = User::factory()->admin()->create();
        $exec = VehicleType::where('slug', 'executive')->first();
        $customer = Customer::create(['name' => 'Acme Travel', 'phone' => '07700900111']);

        Booking::create([
            'reference' => Booking::generateReference(), 'customer_id' => $customer->id,
            'vehicle_type_id' => $exec->id, 'pickup_at' => now()->subDays(2),
            'pickup_address' => 'Sheffield', 'destination_address' => 'Manchester Airport (MAN)',
            'status' => BookingStatus::Complete->value, 'final_price' => 180, 'payment_method' => 'card',
        ]);

        $this->actingAs($admin)->get(route('review.index'))
            ->assertOk()
            ->assertSee('Business Review')
            ->assertSee('Review &amp; recommendations', false)
            ->assertSee('Acme Travel')          // top customer
            ->assertSee('Executive')            // vehicle mix
            ->assertSee('Next steps');
    }

    public function test_all_time_preset_includes_old_bookings_that_last_30_days_excludes(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-07-15 12:00:00'));
        $admin = User::factory()->admin()->create();
        $exec = VehicleType::where('slug', 'executive')->first();
        $customer = Customer::create(['name' => 'Old Client Ltd', 'phone' => '07700900222']);

        Booking::create([
            'reference' => Booking::generateReference(), 'customer_id' => $customer->id,
            'vehicle_type_id' => $exec->id, 'pickup_at' => now()->subMonths(8),
            'pickup_address' => 'Sheffield', 'destination_address' => 'Manchester Airport (MAN)',
            'status' => BookingStatus::Complete->value, 'final_price' => 250, 'payment_method' => 'card',
        ]);

        // Default (last 30 days) → the 8-month-old job is out of range.
        $this->actingAs($admin)->get(route('review.index'))
            ->assertOk()->assertDontSee('Old Client Ltd');

        // "All time" preset → it's included.
        $this->actingAs($admin)->get(route('review.index', ['preset' => 'all']))
            ->assertOk()->assertSee('Old Client Ltd');
    }

    public function test_review_is_admin_only(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->get(route('review.index'))->assertForbidden();
    }
}
