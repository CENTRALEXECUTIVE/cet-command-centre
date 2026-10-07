<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Push to Google Calendar" — the Command Centre is the source of truth, so this
 * sends the booking's details TO the calendar (the inverse of the old "match
 * calendar", which pulled the calendar in as the truth).
 */
class PushToCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_pushing_rebuilds_the_calendar_event_from_the_booking(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create([
            'pickup_address' => '1 Command Centre Way, Sheffield',
            'destination_address' => 'Manchester Airport',
        ]);

        $this->actingAs($admin)->post(route('bookings.push-calendar', $booking))
            ->assertRedirect()
            ->assertSessionHas('status');

        // An event now exists for the booking, built from its own details.
        $this->assertDatabaseHas('calendar_events', ['booking_id' => $booking->id]);
        $this->assertStringContainsString(
            '1 Command Centre Way, Sheffield',
            (string) $booking->fresh()->calendarEvent?->location,
        );
    }

    public function test_push_is_admin_only(): void
    {
        $client = User::factory()->corporateClient()->create();
        $booking = Booking::factory()->create();

        $this->actingAs($client)->post(route('bookings.push-calendar', $booking))->assertForbidden();
    }
}
