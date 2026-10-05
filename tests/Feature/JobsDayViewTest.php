<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CalendarEvent;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobsDayViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_today_view_shows_full_job_detail_from_the_database(): void
    {
        $admin = User::factory()->admin()->create();
        // The booking RECORD is the source of truth — the detail block is rendered
        // live from these fields, never a frozen calendar snapshot.
        $booking = Booking::create([
            'reference' => Booking::generateReference(), 'external_reference' => 'ABC123',
            'customer_id' => Customer::create(['name' => 'Rebecca Fielding', 'phone' => '07889375142'])->id,
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->first()->id,
            'pickup_at' => now()->setTime(6, 0), 'pickup_address' => '81 Hallam Grange Road, Sheffield',
            'destination_address' => 'Manchester Airport (MAN)', 'passengers' => 3, 'flight_number' => 'LS919',
            'status' => 'pending', 'payment_method' => 'card', 'quoted_price' => 100, 'payment_status' => 'paid',
        ]);
        // A STALE calendar snapshot must NOT be what shows.
        CalendarEvent::create([
            'booking_id' => $booking->id, 'calendar_id' => 'admin@centralexecutivetransfers.co.uk',
            'title' => '*Rebecca Fielding MAN (MAJ)*',
            'description' => "STALE: wrong flight ZZ999 and WRONGREF",
            'start_at' => now()->setTime(6, 0), 'end_at' => now()->setTime(7, 0),
            'timezone' => 'Europe/London', 'sync_status' => 'synced',
        ]);

        $this->actingAs($admin)->get(route('jobs.day'))
            ->assertOk()
            ->assertSee('Rebecca Fielding')
            ->assertSee('Flight Number:', false)   // full detail rendered live from the booking
            ->assertSee('LS919')
            ->assertSee('ABC123')
            ->assertDontSee('STALE')               // the frozen calendar text is never shown
            ->assertDontSee('ZZ999');
    }

    public function test_a_system_booking_not_on_the_calendar_still_shows(): void
    {
        // The "Maria" case: a partner job added straight to the system, with NO
        // calendar event. It shows on the dispatch board (DB) but was being
        // dropped from the Jobs day view (calendar-only). It must now appear so
        // the two screens agree.
        $admin = User::factory()->admin()->create();
        Booking::create([
            'reference' => Booking::generateReference(), 'external_reference' => 'H7424',
            'customer_id' => Customer::create(['name' => 'Maria'])->id,
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->first()->id,
            'pickup_at' => '2026-09-23 17:15', 'pickup_address' => 'AMRC, Rotherham',
            'destination_address' => 'Sheffield Train Station', 'passengers' => 1,
            'status' => 'pending', 'payment_method' => 'account',
        ]);

        $this->actingAs($admin)->get(route('jobs.day', ['date' => '2026-09-23']))
            ->assertOk()
            ->assertSee('Maria')
            ->assertSee('H7424');
    }

    public function test_title_shows_the_allocated_driver_not_the_vehicle_type(): void
    {
        $kash = User::factory()->create(['role' => 'driver', 'email' => 'kash@cet.local', 'name' => 'Kash']);
        $booking = Booking::create([
            'reference' => Booking::generateReference(),
            'customer_id' => Customer::create(['name' => 'Nigel Corfield'])->id,
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->first()->id,
            'driver_id' => $kash->id,
            'pickup_at' => '2026-10-03 22:55', 'pickup_address' => 'Birmingham Airport',
            'destination_address' => 'Sheffield', 'passengers' => 6,
            'status' => 'accepted', 'payment_method' => 'card', 'payment_status' => 'paid',
        ]);
        // A STALE stored event title (wrong "Return", wrong "V CLASS") must be
        // ignored — the board title is built LIVE from the booking's own fields.
        CalendarEvent::create([
            'booking_id' => $booking->id, 'title' => '*Nigel Corfield BHX Return (V CLASS)*',
            'start_at' => $booking->pickup_at, 'end_at' => $booking->pickup_at->copy()->addHour(),
            'sync_status' => 'synced',
        ]);

        // Allocated → tag is the driver's callsign (live), not the stale "V CLASS".
        $this->assertSame('Nigel Corfield BHX (KASH)', $booking->boardTitle());

        // Not allocated → the tag is the booking's real vehicle type (live).
        $booking->update(['driver_id' => null]);
        $this->assertSame('Nigel Corfield BHX (EXECUTIVE)', $booking->fresh()->boardTitle());
    }

    public function test_board_title_reflects_a_corrected_airport_over_a_stale_free_roam_title(): void
    {
        // The real GJDPJF case: an ETO Heathrow job first mis-read as FREE ROAM,
        // frozen into its calendar-event title. The board title must now show the
        // airport (LHR) live from the booking — not the stale "FREE ROAM".
        $booking = Booking::create([
            'reference' => Booking::generateReference(), 'external_reference' => 'GJDPJF',
            'customer_id' => Customer::create(['name' => 'Callum Lindsay'])->id,
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->first()->id,
            'source_system' => 'eto',
            'pickup_at' => '2026-10-08 16:10',
            'pickup_address' => 'The Diamond, 32 Leavygreave Road, Broomhall, Sheffield',
            'destination_address' => 'Terminal 5, Wallis Road, Longford, Hounslow, UK',
            'passengers' => 1, 'status' => 'allocated', 'payment_method' => 'card', 'payment_status' => 'paid',
        ]);
        CalendarEvent::create([
            'booking_id' => $booking->id, 'title' => '*Callum Lindsay FREE ROAM (MAJID)*',
            'start_at' => $booking->pickup_at, 'end_at' => $booking->pickup_at->copy()->addHour(),
            'sync_status' => 'synced',
        ]);

        $this->assertStringContainsString('LHR', $booking->boardTitle());
        $this->assertStringNotContainsString('FREE ROAM', $booking->boardTitle());
        $this->assertSame('LHR', $booking->routeGroupKey());  // and it classifies as an airport job
    }

    public function test_day_view_navigates_by_date(): void
    {
        // Pin "now" so the target date is never "today" (which renders as "Today"
        // rather than the full date) — keeps this deterministic year-round.
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-07-15 12:00:00'));
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('jobs.day', ['date' => '2026-07-20']))
            ->assertOk()->assertSee('20 July 2026');
    }
}
