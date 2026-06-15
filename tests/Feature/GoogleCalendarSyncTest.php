<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CalendarEvent;
use App\Models\VehicleType;
use App\Services\Calendar\GoogleCalendarService;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoogleCalendarSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function event(): CalendarEvent
    {
        $booking = Booking::factory()->forVehicleType(VehicleType::where('slug', 'executive')->first())->create();

        return CalendarEvent::create([
            'booking_id' => $booking->id,
            'title' => '*Test MAN (ABDI)*',
            'location' => 'Manchester Airport',
            'start_at' => now()->addDay(),
            'end_at' => now()->addDay()->addHour(),
            'sync_status' => 'pending',
        ]);
    }

    public function test_unconfigured_leaves_event_pending(): void
    {
        $event = $this->event();

        $this->assertFalse(app(GoogleCalendarService::class)->configured());
        $this->assertFalse(app(GoogleCalendarService::class)->push($event));
        $this->assertEquals('pending', $event->fresh()->sync_status);
    }

    public function test_sync_command_runs_and_skips_when_unconfigured(): void
    {
        $this->event();
        $this->artisan('cet:sync-calendar')->assertSuccessful();
        // Still pending because credentials are absent.
        $this->assertEquals('pending', CalendarEvent::first()->sync_status);
    }
}
