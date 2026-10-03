<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_month_calendar_shows_the_month_and_its_bookings(): void
    {
        $admin = User::factory()->admin()->create();
        Booking::create([
            'reference' => Booking::generateReference(),
            'customer_id' => Customer::create(['name' => 'Nigel Corfield'])->id,
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->first()->id,
            'pickup_at' => '2026-10-03 22:55', 'pickup_address' => 'Birmingham Airport',
            'destination_address' => 'Sheffield', 'passengers' => 6,
            'status' => 'accepted', 'payment_method' => 'card',
        ]);

        $this->actingAs($admin)->get(route('calendar.index', ['month' => '2026-10']))
            ->assertOk()
            ->assertSee('October 2026')
            ->assertSee('Nigel Corfield')
            ->assertSee('22:55')
            // Each day links to its Jobs day view.
            ->assertSee(route('jobs.day', ['date' => '2026-10-03']), false);
    }

    public function test_cancelled_bookings_are_not_shown(): void
    {
        $admin = User::factory()->admin()->create();
        Booking::create([
            'reference' => Booking::generateReference(),
            'customer_id' => Customer::create(['name' => 'Ghost Job'])->id,
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->first()->id,
            'pickup_at' => '2026-10-10 09:00', 'pickup_address' => 'A', 'destination_address' => 'B',
            'passengers' => 1, 'status' => 'cancelled', 'payment_method' => 'card',
        ]);

        $this->actingAs($admin)->get(route('calendar.index', ['month' => '2026-10']))
            ->assertOk()->assertDontSee('Ghost Job');
    }

    public function test_non_admin_cannot_view_calendar(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->get(route('calendar.index'))->assertForbidden();
    }
}
