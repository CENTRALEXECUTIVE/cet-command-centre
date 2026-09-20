<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\AirportSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouteOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([AirportSeeder::class, VehicleTypeSeeder::class]);
    }

    public function test_route_order_lists_a_routes_bookings_in_order_with_drivers(): void
    {
        $admin = User::factory()->admin()->create();
        $abdi = User::factory()->driver()->create(['name' => 'Abdi']);
        $maj = User::factory()->driver()->create(['name' => 'Maj']);

        // Two MAN jobs + a Free Roam job. Maj's job came through LATER, so it
        // should appear first (newest "came in" at the top).
        Booking::factory()->create([
            'driver_id' => $abdi->id, 'pickup_at' => now()->addDay()->setTime(9, 0),
            'created_at' => now()->subMinutes(20),
            'pickup_address' => 'Manchester Airport (MAN)', 'destination_address' => 'Sheffield',
        ]);
        Booking::factory()->create([
            'driver_id' => $maj->id, 'pickup_at' => now()->addDay()->setTime(14, 0),
            'created_at' => now()->subMinutes(5),
            'pickup_address' => 'Sheffield', 'destination_address' => 'Manchester Airport (MAN)',
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
        // Newest "came in" first: Maj (came through 5 min ago) before Abdi (20 min ago).
        $this->assertLessThan(
            strpos($res->getContent(), 'Abdi'),
            strpos($res->getContent(), 'Maj'),
        );

        // Free Roam tab is offered and lists the roam job.
        $this->actingAs($admin)->get(route('route-order.index', ['route' => 'Free Roam']))
            ->assertOk()->assertSee('Peaky Roamer');

        // The same airport order is embedded on the Driver rotation page (executive).
        $this->actingAs($admin)->get(route('rotation.index', ['route' => 'MAN']))
            ->assertOk()
            ->assertSee('Airport order')
            ->assertSee('Abdi')
            ->assertSee('Maj');
    }

    public function test_the_rotation_page_airport_order_is_executive_only(): void
    {
        $admin = User::factory()->admin()->create();
        $abdi = User::factory()->driver()->create(['name' => 'Abdi']);
        $cover = User::factory()->driver()->create(['name' => 'Cover Carl']);
        $exec = VehicleType::where('slug', 'executive')->first();
        $minibus = VehicleType::where('slug', 'minibus-8')->first();

        // Executive MAN job (rotates) + a minibus MAN job (cover, doesn't rotate).
        Booking::factory()->create([
            'driver_id' => $abdi->id, 'vehicle_type_id' => $exec->id,
            'pickup_at' => now()->addDay()->setTime(9, 0),
            'pickup_address' => 'Manchester Airport (MAN)', 'destination_address' => 'Sheffield',
        ]);
        Booking::factory()->create([
            'driver_id' => $cover->id, 'vehicle_type_id' => $minibus->id,
            'pickup_at' => now()->addDay()->setTime(10, 0),
            'pickup_address' => 'Manchester Airport (MAN)', 'destination_address' => 'Leeds',
        ]);

        // The rotation page's MAN airport shows ONLY the executive job's driver —
        // the minibus cover job is not part of the rotation and is excluded.
        $this->actingAs($admin)->get(route('rotation.index', ['route' => 'MAN']))
            ->assertOk()
            ->assertSee('Abdi')
            ->assertDontSee('Cover Carl');
    }

    public function test_route_order_can_filter_to_executive_only(): void
    {
        $admin = User::factory()->admin()->create();
        $abdi = User::factory()->driver()->create(['name' => 'Abdi']);
        $cover = User::factory()->driver()->create(['name' => 'Cover Carl']);
        $exec = VehicleType::where('slug', 'executive')->first();
        $minibus = VehicleType::where('slug', 'minibus-8')->first();

        // An executive MAN job (rotates Abdi/Maj) and a minibus MAN job (cover).
        Booking::factory()->create([
            'driver_id' => $abdi->id, 'vehicle_type_id' => $exec->id, 'pickup_at' => now()->addDay()->setTime(9, 0),
            'pickup_address' => 'Manchester Airport (MAN)', 'destination_address' => 'Sheffield',
        ]);
        Booking::factory()->create([
            'driver_id' => $cover->id, 'vehicle_type_id' => $minibus->id, 'pickup_at' => now()->addDay()->setTime(10, 0),
            'pickup_address' => 'Manchester Airport (MAN)', 'destination_address' => 'Leeds',
        ]);

        // Executive filter shows Abdi's exec job, not the minibus cover driver.
        $this->actingAs($admin)->get(route('route-order.index', ['route' => 'MAN', 'vehicle' => 'Executive']))
            ->assertOk()
            ->assertSee('Abdi')
            ->assertDontSee('Cover Carl');
    }

    public function test_a_free_roam_airport_code_folds_into_the_free_roam_tag(): void
    {
        // The seeder includes a "FREE_ROAM" airport record. It must NOT appear as
        // a separate empty airport tab — it folds into the real Free Roam tag.
        $this->assertNotNull(\App\Models\Airport::where('code', 'FREE_ROAM')->first());
        $admin = User::factory()->admin()->create();
        $cust = \App\Models\Customer::create(['name' => 'Roamer One', 'phone' => '07700900441']);
        Booking::factory()->create([
            'customer_id' => $cust->id, 'pickup_at' => now()->addDay(),
            'pickup_address' => 'Sheffield', 'destination_address' => 'Peak District',
            'meta' => ['journey_label' => 'Free Roam'],
        ]);

        $res = $this->actingAs($admin)->get(route('route-order.index', ['route' => 'Free Roam']))->assertOk();
        $res->assertSee('Roamer One');
        // No separate FREE_ROAM airport tab.
        $res->assertDontSee('FREE_ROAM');
    }

    public function test_route_order_offers_an_inline_change_driver_control(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create(['name' => 'Rota Ray']);
        \App\Models\DriverProfile::create(['user_id' => $driver->id]);
        Booking::factory()->create([
            'pickup_at' => now()->addDay(), 'created_at' => now(),
            'pickup_address' => 'Manchester Airport (MAN)', 'destination_address' => 'Sheffield',
        ]);

        $this->actingAs($admin)->get(route('route-order.index', ['route' => 'MAN']))
            ->assertOk()
            ->assertSee('Change…')                              // the inline picker
            ->assertSee('despatch/'.Booking::first()->id.'/reassign', false); // posts to reassign
    }

    public function test_route_order_is_admin_only(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->get(route('route-order.index'))->assertForbidden();
    }
}
