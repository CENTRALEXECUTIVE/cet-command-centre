<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\RotationService;
use Database\Seeders\AirportSeeder;
use Database\Seeders\DirectorSeeder;
use Database\Seeders\RotationSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RotationViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([VehicleTypeSeeder::class, DirectorSeeder::class, AirportSeeder::class, RotationSeeder::class]);
    }

    public function test_page_shows_the_airport_order_view(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('rotation.index'))
            ->assertOk()
            ->assertSee('Airport order')
            ->assertSee('Executive only')
            ->assertSee('Upcoming')
            ->assertSee('Abdi')
            ->assertSee('Maj');
    }

    public function test_a_booking_shows_under_its_airport_with_the_rotation_driver(): void
    {
        $admin = User::factory()->admin()->create();
        $executive = VehicleType::where('slug', 'executive')->first();
        $customer = Customer::create(['name' => 'Test Passenger']);
        $airport = \App\Models\Airport::where('code', 'LHR')->first();

        $booking = Booking::create([
            'reference' => Booking::generateReference(),
            'customer_id' => $customer->id,
            'vehicle_type_id' => $executive->id,
            'airport_id' => $airport?->id,
            'pickup_at' => now()->addDay(),
            'pickup_address' => 'Pickup',
            'destination_address' => 'Destination',
            'passengers' => 1,
            'status' => 'pending',
            'payment_method' => 'card',
        ]);
        app(RotationService::class)->allocate($booking);

        // The booking appears in its airport's order list with the assigned driver.
        $this->actingAs($admin)->get(route('rotation.index', ['route' => 'LHR']))
            ->assertOk()
            ->assertSee('Airport order')
            ->assertSee($booking->reference);
    }

    public function test_the_turn_panel_shows_whose_turn_and_who_did_the_last_job(): void
    {
        $admin = User::factory()->admin()->create();
        $executive = VehicleType::where('slug', 'executive')->first();
        $customer = Customer::create(['name' => 'Rotation Passenger']);
        $airport = \App\Models\Airport::where('code', 'LHR')->first();

        $booking = Booking::create([
            'reference' => Booking::generateReference(),
            'customer_id' => $customer->id,
            'vehicle_type_id' => $executive->id,
            'airport_id' => $airport?->id,
            'pickup_at' => now()->addDay(),
            'pickup_address' => 'Pickup',
            'destination_address' => 'Destination',
            'passengers' => 1,
            'status' => 'pending',
            'payment_method' => 'card',
        ]);
        // Abdi (first driver) takes it; the pointer advances to Maj.
        $driver = app(RotationService::class)->allocate($booking);

        $this->actingAs($admin)->get(route('rotation.index'))
            ->assertOk()
            ->assertSee('Whose turn')
            ->assertSee('Turn now')
            ->assertSee('Last job')
            // The driver who just went is still shown next to whose turn it is now.
            ->assertSee($driver->name)
            ->assertSee($booking->reference);
    }

    public function test_the_turn_panel_has_an_inline_set_turn_control(): void
    {
        $admin = User::factory()->admin()->create();
        $executive = VehicleType::where('slug', 'executive')->first();
        $customer = Customer::create(['name' => 'Editable Turn']);
        $airport = \App\Models\Airport::where('code', 'LHR')->first();
        $booking = Booking::create([
            'reference' => Booking::generateReference(),
            'customer_id' => $customer->id,
            'vehicle_type_id' => $executive->id,
            'airport_id' => $airport?->id,
            'pickup_at' => now()->addDay(),
            'pickup_address' => 'Pickup', 'destination_address' => 'Destination',
            'passengers' => 1, 'status' => 'pending', 'payment_method' => 'card',
        ]);
        app(RotationService::class)->allocate($booking);

        $this->actingAs($admin)->get(route('rotation.index'))->assertOk()
            ->assertSee('Set turn')
            ->assertSee(route('rotation.set-next'), false);
    }

    public function test_the_history_log_shows_past_jobs_and_who_did_them(): void
    {
        $admin = User::factory()->admin()->create();
        $executive = VehicleType::where('slug', 'executive')->first();
        $airport = \App\Models\Airport::where('code', 'LHR')->first();

        // Two allocations build the rotation history (one each to Abdi then Maj).
        foreach (['Alpha Passenger', 'Bravo Passenger'] as $name) {
            $booking = Booking::create([
                'reference' => Booking::generateReference(),
                'customer_id' => Customer::create(['name' => $name])->id,
                'vehicle_type_id' => $executive->id, 'airport_id' => $airport?->id,
                'pickup_at' => now()->addDay(), 'pickup_address' => 'Pickup',
                'destination_address' => 'Destination', 'passengers' => 1,
                'status' => 'pending', 'payment_method' => 'card',
            ]);
            app(RotationService::class)->allocate($booking);
        }

        $this->actingAs($admin)->get(route('rotation.index'))
            ->assertOk()
            ->assertSee('Rotation history')
            ->assertSee('Whose turn', false)   // the column header
            ->assertSee('Alpha Passenger')      // a past job is listed
            ->assertSee('Bravo Passenger');
    }

    public function test_non_admin_cannot_view_rotation(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->get(route('rotation.index'))->assertForbidden();
    }
}
