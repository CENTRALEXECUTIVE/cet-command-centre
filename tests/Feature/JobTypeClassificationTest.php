<?php

namespace Tests\Feature;

use App\Models\Airport;
use App\Models\Booking;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Every booking auto-recognises what KIND of job it is (a specific airport, or a
 * free-roam run), shown on the booking with a driver/rotation section the office
 * can use to correct an allocation.
 */
class JobTypeClassificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
        Cache::forget('airport_code_names');
    }

    public function test_an_airport_run_is_recognised_by_name(): void
    {
        Airport::create(['code' => 'MAN', 'name' => 'Manchester Airport', 'is_active' => true]);
        Cache::forget('airport_code_names');

        $exec = VehicleType::where('slug', 'executive')->firstOrFail();
        $booking = Booking::factory()->forVehicleType($exec)->create([
            'pickup_address' => 'Manchester Airport (MAN)',
            'destination_address' => 'Sheffield S1 2HH',
        ]);

        $this->assertStringContainsString('Manchester Airport', $booking->jobTypeLabel());
        $this->assertStringContainsString('(MAN)', $booking->jobTypeLabel());
        $this->assertTrue($booking->onDriverRotation()); // executive
    }

    public function test_a_plain_run_is_recognised_as_free_roam(): void
    {
        $estate = VehicleType::where('slug', 'estate')->firstOrFail();
        $booking = Booking::factory()->forVehicleType($estate)->create([
            'pickup_address' => 'Sheffield S1 2HH',
            'destination_address' => 'Chesterfield S40 1AA',
        ]);

        $this->assertSame('Free-roam job (distance priced)', $booking->jobTypeLabel());
        $this->assertFalse($booking->onDriverRotation()); // estate isn't on rotation
    }

    public function test_the_booking_page_shows_the_job_type_and_driver_section(): void
    {
        $admin = User::factory()->admin()->create();
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();
        $booking = Booking::factory()->forVehicleType($exec)->create([
            'pickup_address' => 'Sheffield S1 2HH',
            'destination_address' => 'Leeds LS1 1AA',
        ]);

        $this->actingAs($admin)->get(route('bookings.show', $booking))->assertOk()
            ->assertSee('Job type &amp; driver', false)
            ->assertSee('Recognised as:', false);
    }
}
