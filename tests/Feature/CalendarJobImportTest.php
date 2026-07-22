<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Message;
use App\Models\User;
use App\Services\Calendar\CalendarStats;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CalendarJobImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function fakeParsed(): array
    {
        return [
            'event_id' => 'evt_safah_123',
            'calendar_id' => 'admin@centralexecutivetransfers.co.uk',
            'title' => '*Safah Rajah MAN (V CLASS)*',
            'location' => '28 Batworth Drive, Sheffield S5 8XX',
            'description' => 'Booking Reference: U6NAEK',
            'reference' => 'U6NAEK',
            'customer_name' => 'Safah Rajah',
            'customer_phone' => '+447526247607',
            'pickup_at' => Carbon::now()->addDays(1)->setTime(11, 30),
            'end_at' => Carbon::now()->addDays(1)->setTime(12, 30),
            'pickup_address' => '28 Batworth Drive, Sheffield S5 8XX',
            'destination_address' => 'Manchester Airport (MAN)',
            'passengers' => 2,
            'luggage' => 2,
            'luggage_text' => '2 Suitcases',
            'flight_number' => null,
            'vehicle_label' => 'V Class',
            'payment_text' => 'Paid £165 (Square)',
            'notes' => null,
            'driver_tag' => null,
        ];
    }

    public function test_admin_adds_a_calendar_only_job_to_bookings(): void
    {
        $this->mock(CalendarStats::class, function ($mock) {
            $mock->shouldReceive('eventToBookingData')->with('evt_safah_123')->andReturn($this->fakeParsed());
        });

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)
            ->post(route('jobs.import'), ['event_id' => 'evt_safah_123'])
            ->assertRedirect();

        $booking = Booking::where('external_reference', 'U6NAEK')->first();
        $this->assertNotNull($booking);
        $this->assertEquals('Manchester Airport (MAN)', $booking->destination_address);
        $this->assertEquals('165.00', (string) $booking->quoted_price);
        $this->assertEquals('paid', $booking->payment_status);
        $this->assertEquals('Safah Rajah', $booking->customer->name);

        // The existing Google event is LINKED (not duplicated) and marked synced.
        $this->assertNotNull($booking->calendarEvent);
        $this->assertEquals('evt_safah_123', $booking->calendarEvent->google_event_id);
        $this->assertEquals('synced', $booking->calendarEvent->sync_status);

        // A reminder is prepared, ready to send.
        $this->assertGreaterThan(0, Message::where('booking_id', $booking->id)->where('type', 'reminder_24h')->count());
    }

    public function test_a_reference_less_event_matches_an_existing_booking_and_is_not_duplicated(): void
    {
        // An existing booking for this journey (no external reference).
        $customer = \App\Models\Customer::create(['name' => 'Safah Rajah', 'phone' => '+447526247607']);
        $existing = Booking::factory()
            ->forVehicleType(\App\Models\VehicleType::where('slug', 'executive')->first())
            ->create([
                'customer_id' => $customer->id,
                'pickup_at' => Carbon::now()->addDays(1)->setTime(11, 30),
                'destination_address' => 'Manchester Airport (MAN)',
            ]);

        // A calendar event with NO booking reference for the same journey.
        $parsed = $this->fakeParsed();
        $parsed['reference'] = null;
        $parsed['event_id'] = 'evt_no_ref';

        $found = app(\App\Services\Calendar\CalendarJobImporter::class)->existingBookingFor($parsed);

        $this->assertNotNull($found, 'The reference-less event should match the existing booking, not duplicate it.');
        $this->assertSame($existing->id, $found->id);
    }

    public function test_importing_an_already_known_job_just_opens_it(): void
    {
        $existing = Booking::factory()
            ->forVehicleType(\App\Models\VehicleType::where('slug', 'executive')->first())
            ->create(['external_reference' => 'U6NAEK']);

        $this->mock(CalendarStats::class, function ($mock) {
            $mock->shouldReceive('eventToBookingData')->andReturn($this->fakeParsed());
        });

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)
            ->post(route('jobs.import'), ['event_id' => 'evt_safah_123'])
            ->assertRedirect(route('bookings.show', $existing));

        // No duplicate created.
        $this->assertEquals(1, Booking::where('external_reference', 'U6NAEK')->count());
    }

    public function test_non_admin_cannot_import(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)
            ->post(route('jobs.import'), ['event_id' => 'evt_safah_123'])
            ->assertForbidden();
    }
}
