<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use Database\Seeders\AirportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouteOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AirportSeeder::class);
    }

    public function test_route_order_lists_a_routes_bookings_in_order_with_drivers(): void
    {
        $admin = User::factory()->admin()->create();
        $abdi = User::factory()->driver()->create(['name' => 'Abdi']);
        $maj = User::factory()->driver()->create(['name' => 'Maj']);

        // Two MAN jobs (out of order on create) + a Free Roam job.
        Booking::factory()->create([
            'driver_id' => $maj->id, 'pickup_at' => now()->addDay()->setTime(14, 0),
            'pickup_address' => 'Sheffield', 'destination_address' => 'Manchester Airport (MAN)',
        ]);
        Booking::factory()->create([
            'driver_id' => $abdi->id, 'pickup_at' => now()->addDay()->setTime(9, 0),
            'pickup_address' => 'Manchester Airport (MAN)', 'destination_address' => 'Sheffield',
        ]);
        $roamCustomer = \App\Models\Customer::create(['name' => 'Peaky Roamer', 'phone' => '07700900431']);
        Booking::factory()->create([
            'customer_id' => $roamCustomer->id,
            'pickup_at' => now()->addDay()->setTime(10, 0),
            'pickup_address' => 'Sheffield City', 'destination_address' => 'Peak District',
            'meta' => ['journey_label' => 'Free Roam'],
        ]);

        // MAN route: both MAN jobs, earliest first, with their drivers.
        $res = $this->actingAs($admin)->get(route('route-order.index', ['route' => 'MAN']))->assertOk();
        $res->assertSee('Route order');
        $res->assertSee('Abdi');
        $res->assertSee('Maj');
        // Earliest (Abdi 09:00) appears before the later one (Maj 14:00).
        $this->assertLessThan(
            strpos($res->getContent(), 'Maj'),
            strpos($res->getContent(), 'Abdi'),
        );

        // Free Roam tab is offered and lists the roam job.
        $this->actingAs($admin)->get(route('route-order.index', ['route' => 'Free Roam']))
            ->assertOk()->assertSee('Peaky Roamer');
    }

    public function test_route_order_is_admin_only(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->get(route('route-order.index'))->assertForbidden();
    }
}
