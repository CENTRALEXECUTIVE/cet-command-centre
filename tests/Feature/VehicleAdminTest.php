<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Office "super control" of the fleet classes customers see — name, subtitle,
 * capacities, hand luggage, on/off and order — with no code changes. Subtitle and
 * hand luggage have no DB column, so they live in the shared vehicle_meta Setting.
 */
class VehicleAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([VehicleTypeSeeder::class]);
    }

    public function test_admin_can_edit_a_vehicle(): void
    {
        $admin = User::factory()->admin()->create();
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();

        $this->actingAs($admin)->get(route('vehicles.index'))->assertOk()->assertSee('Executive');

        $this->actingAs($admin)->put(route('vehicles.update', $exec), [
            'name' => 'Executive Saloon',
            'tagline' => 'Mercedes E-Class only',
            'passenger_capacity' => 3,
            'luggage_capacity' => 2,
            'hand_luggage_capacity' => 3,
            'sort_order' => 1,
            'is_active' => '1',
        ])->assertRedirect();

        $exec->refresh();
        $this->assertSame('Executive Saloon', $exec->name);
        $this->assertSame(3, $exec->passenger_capacity);
        $this->assertSame(2, $exec->luggage_capacity);
        $this->assertSame(1, $exec->sort_order);
        $this->assertTrue($exec->is_active);

        // Subtitle + hand luggage come back from the Setting override.
        $this->assertSame('Mercedes E-Class only', $exec->tagline());
        $this->assertSame(3, $exec->handLuggageCapacity());
    }

    public function test_admin_can_hide_a_vehicle_from_customers(): void
    {
        $admin = User::factory()->admin()->create();
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();

        $this->actingAs($admin)->put(route('vehicles.update', $exec), [
            'name' => $exec->name,
            'passenger_capacity' => $exec->passenger_capacity,
            'luggage_capacity' => $exec->luggage_capacity,
            'hand_luggage_capacity' => 2,
            'sort_order' => $exec->sort_order,
            // is_active omitted → unchecked → hidden.
        ])->assertRedirect();

        $this->assertFalse($exec->refresh()->is_active);
    }

    public function test_a_blank_subtitle_clears_the_override_back_to_the_default(): void
    {
        $admin = User::factory()->admin()->create();
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();

        Setting::set('vehicle_meta', ['executive' => ['tagline' => 'Custom', 'hand_luggage' => 5]], 'json', 'fleet');
        $this->assertSame('Custom', $exec->tagline());

        $this->actingAs($admin)->put(route('vehicles.update', $exec), [
            'name' => $exec->name,
            'tagline' => '',
            'passenger_capacity' => $exec->passenger_capacity,
            'luggage_capacity' => $exec->luggage_capacity,
            'hand_luggage_capacity' => 2,
            'sort_order' => $exec->sort_order,
            'is_active' => '1',
        ])->assertRedirect();

        // Falls back to the built-in default subtitle.
        $this->assertSame('Mercedes E/S-Class', $exec->refresh()->tagline());
    }

    public function test_a_non_admin_cannot_manage_vehicles(): void
    {
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();
        $client = User::factory()->corporateClient()->create();

        $this->actingAs($client)->get(route('vehicles.index'))->assertForbidden();
        $this->actingAs($client)->put(route('vehicles.update', $exec), [
            'name' => 'Hacked',
            'passenger_capacity' => 1,
            'luggage_capacity' => 1,
            'hand_luggage_capacity' => 1,
            'sort_order' => 1,
        ])->assertForbidden();

        $this->assertNotSame('Hacked', $exec->refresh()->name);
    }
}
