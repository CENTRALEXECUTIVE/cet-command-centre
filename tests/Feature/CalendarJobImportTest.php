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

    public function test_importing_an_already_known_job_offers_open_or_add_separate(): void
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
            ->assertSessionHas('force_add_event', 'evt_safah_123')
            ->assertSessionHas('force_add_matched', $existing->reference);

        // Nothing was created — the operator chooses to open it or add separately.
        $this->assertEquals(1, Booking::where('external_reference', 'U6NAEK')->count());
    }

    public function test_two_different_customers_to_the_same_airport_at_the_same_time_are_not_collapsed(): void
    {
        // Booking already in the system: Alice → Manchester Airport at 11:30.
        $at = Carbon::now()->addDays(1)->setTime(11, 30);
        $alice = \App\Models\Customer::create(['name' => 'Alice Adams', 'phone' => '+447526240001']);
        Booking::factory()->forVehicleType(\App\Models\VehicleType::where('slug', 'executive')->first())->create([
            'customer_id' => $alice->id, 'pickup_at' => $at,
            'destination_address' => 'Manchester Airport (MAN)',
        ]);

        // A reference-less calendar event for a DIFFERENT customer, same airport,
        // same minute. It must NOT match Alice's booking (shared drop-off ≠ same job).
        $parsed = $this->fakeParsed();
        $parsed['reference'] = null;
        $parsed['event_id'] = 'evt_bob';
        $parsed['customer_name'] = 'Bob Baker';
        $parsed['customer_phone'] = '+447526240002';
        $parsed['pickup_at'] = $at;
        $parsed['destination_address'] = 'Manchester Airport (MAN)';

        $found = app(\App\Services\Calendar\CalendarJobImporter::class)->existingBookingFor($parsed);
        $this->assertNull($found, 'Two different customers to the same airport at the same time are two bookings, not one.');
    }

    public function test_force_add_imports_a_separate_booking_and_releases_a_merged_alias(): void
    {
        // The outbound leg wrongly holds the return's reference as a merged alias,
        // so a normal add says "already in bookings". Force-add must create the
        // return as its own booking and free the alias.
        $outbound = Booking::factory()
            ->forVehicleType(\App\Models\VehicleType::where('slug', 'executive')->first())
            ->create([
                'external_reference' => 'U7YGIH',
                'meta' => ['merged_references' => ['LLYHB3']],
            ]);

        $parsed = $this->fakeParsed();
        $parsed['reference'] = 'LLYHB3';
        $parsed['event_id'] = 'evt_return';
        $parsed['customer_name'] = 'Michael Macfarlane';

        $importer = app(\App\Services\Calendar\CalendarJobImporter::class);
        // Sanity: a normal add would be blocked (resolves to the outbound alias).
        $this->assertNotNull($importer->existingBookingFor($parsed));

        $mock = $this->mock(CalendarStats::class);
        $mock->shouldReceive('eventToBookingData')->with('evt_return')->andReturn($parsed);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)
            ->post(route('jobs.import'), ['event_id' => 'evt_return', 'force' => '1'])
            ->assertRedirect();

        // The return now exists as its own booking…
        $return = Booking::where('external_reference', 'LLYHB3')->first();
        $this->assertNotNull($return);
        $this->assertNotSame($outbound->id, $return->id);
        // …and the outbound no longer claims LLYHB3.
        $this->assertNotContains('LLYHB3', (array) ($outbound->fresh()->meta['merged_references'] ?? []));
    }

    public function test_non_admin_cannot_import(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)
            ->post(route('jobs.import'), ['event_id' => 'evt_safah_123'])
            ->assertForbidden();
    }
}
