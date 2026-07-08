<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BookingIntakeService;
use Database\Seeders\AirportSeeder;
use Database\Seeders\DirectorSeeder;
use Database\Seeders\RotationSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Paste a booking → the preview says which driver the rotation gives the job,
 * BEFORE confirming — and peeking never advances the rotation pointer.
 */
class IntakeNextDriverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([VehicleTypeSeeder::class, DirectorSeeder::class, AirportSeeder::class, RotationSeeder::class]);
    }

    public function test_next_rotation_driver_is_shown_for_an_executive_airport_job(): void
    {
        $next = app(BookingIntakeService::class)->nextDriver([
            'vehicle' => 'Executive', 'where' => 'MAN',
        ]);

        $this->assertNotNull($next, 'An executive airport job must name the next rotation driver.');
        $this->assertNotSame('', $next['tag']);

        // Peeking twice returns the same driver — the pointer has NOT moved.
        $again = app(BookingIntakeService::class)->nextDriver([
            'vehicle' => 'Executive', 'where' => 'MAN',
        ]);
        $this->assertSame($next['tag'], $again['tag']);
    }

    public function test_preview_page_shows_the_next_driver_banner(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('intake.preview'), [
            'raw' => "• *Date & Time:* 24/11/2026 – 07:30\n• *Customer Name:* Emma Cusworth\n"
                ."• *Pickup Location:* Manchester Airport (MAN), Terminal 2\n"
                ."• *Drop-off Location:* Barnsley S73 0YA\n• *Vehicle Type:* Executive",
        ])->assertOk()
            ->assertSee('Next in rotation')
            ->assertSee('rotation only moves when you press Confirm');
    }

    public function test_operator_can_correct_a_rotation_pointer_and_the_preview_follows(): void
    {
        $admin = User::factory()->admin()->create();
        $intake = app(BookingIntakeService::class);
        $rotation = app(\App\Services\RotationService::class);

        $airport = \App\Models\Airport::where('code', 'MAN')->firstOrFail();
        $vehicleType = \App\Models\VehicleType::where('affects_rotation', true)->firstOrFail();

        $before = $intake->nextDriver(['vehicle' => $vehicleType->name, 'where' => 'MAN']);
        // Flip the pointer to the OTHER rotation driver via the new control.
        $other = $rotation->order()->firstWhere('name', '!=', $before['name']);
        $this->assertNotNull($other, 'There should be two rotation drivers.');

        $this->actingAs($admin)->post(route('rotation.set-next'), [
            'airport_id' => $airport->id,
            'vehicle_type_id' => $vehicleType->id,
            'driver_id' => $other->id,
        ])->assertRedirect()->assertSessionHas('status');

        // The paste-a-booking preview now names the corrected driver.
        $after = $intake->nextDriver(['vehicle' => $vehicleType->name, 'where' => 'MAN']);
        $this->assertSame($other->name, $after['name']);

        // And it's logged as a manual override.
        $this->assertDatabaseHas('rotation_logs', [
            'airport_id' => $airport->id,
            'to_driver_id' => $other->id,
            'reason' => 'manual_override',
        ]);
    }

    public function test_only_admins_can_set_the_pointer(): void
    {
        $driver = User::factory()->driver()->create();
        $airport = \App\Models\Airport::where('code', 'MAN')->firstOrFail();
        $vehicleType = \App\Models\VehicleType::where('affects_rotation', true)->firstOrFail();

        $this->actingAs($driver)->post(route('rotation.set-next'), [
            'airport_id' => $airport->id,
            'vehicle_type_id' => $vehicleType->id,
            'driver_id' => $driver->id,
        ])->assertForbidden();
    }

    public function test_non_rotation_vehicles_say_allocate_manually(): void
    {
        $next = app(BookingIntakeService::class)->nextDriver([
            'vehicle' => 'V Class', 'where' => 'MAN',
        ]);

        // V Class sits outside the executive-saloon rotation.
        $this->assertNull($next);
    }
}
