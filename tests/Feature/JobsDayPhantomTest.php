<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use App\Services\Calendar\CalendarStats;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

/**
 * The Jobs-by-day view must not show a STALE calendar event as a phantom on the
 * wrong day. If a booking's date was changed (in the app or ETO), its old Google
 * Calendar snapshot would otherwise appear on the old day next to the real one —
 * exactly the "duplicate" the office saw. The DB record is the source of truth;
 * a calendar event whose reference is already a real booking is dropped. A
 * genuinely calendar-only event is still shown.
 */
class JobsDayPhantomTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_a_stale_calendar_phantom_for_an_existing_booking_is_hidden(): void
    {
        $admin = User::factory()->admin()->create();

        // The REAL booking lives on 08 Oct (its true date, from its own ETO email).
        Booking::factory()->create([
            'external_reference' => '9Y5MDRB',
            'pickup_at' => Carbon::parse('2026-10-08 09:00'),
            'pickup_address' => '2 Worrygoose Lane, Rotherham',
            'destination_address' => 'Manchester Airport (MAN)',
        ]);

        // The STALE calendar snapshot still sits on 04 Oct with the old route.
        $stale = [[
            'ref' => '9Y5MDRb', 'pickup' => Carbon::parse('2026-10-04 08:55'),
            'customer' => 'Janine Neill', 'vehicle' => 'Executive', 'driver' => 'ABDI',
            'status' => 'Allocated', 'url' => null, 'title' => 'Janine Neill MAN Return',
            'location' => 'Manchester Airport (MAN)', 'description' => 'STALE PHANTOM', 'flight' => 'LM0693',
        ]];

        $mock = Mockery::mock(CalendarStats::class);
        $mock->shouldReceive('jobsOn')->andReturn($stale);
        $mock->shouldReceive('counts')->andReturn([]);
        $this->app->instance(CalendarStats::class, $mock);

        // On 04 Oct the phantom must NOT appear (the real booking is on the 8th).
        $this->actingAs($admin)->get(route('jobs.day', ['date' => '2026-10-04']))
            ->assertOk()
            ->assertDontSee('STALE PHANTOM');
    }

    public function test_a_calendar_only_event_is_still_shown(): void
    {
        $admin = User::factory()->admin()->create();

        $onlyOnCalendar = [[
            'ref' => 'NOTINDB1', 'pickup' => Carbon::parse('2026-10-04 10:00'),
            'customer' => 'Calendar Only', 'vehicle' => 'Executive', 'driver' => '—',
            'status' => 'Scheduled', 'url' => null, 'title' => 'Calendar Only Job',
            'location' => 'Somewhere', 'description' => 'CALENDAR ONLY JOB', 'flight' => null,
        ]];

        $mock = Mockery::mock(CalendarStats::class);
        $mock->shouldReceive('jobsOn')->andReturn($onlyOnCalendar);
        $mock->shouldReceive('counts')->andReturn([]);
        $this->app->instance(CalendarStats::class, $mock);

        $this->actingAs($admin)->get(route('jobs.day', ['date' => '2026-10-04']))
            ->assertOk()
            ->assertSee('CALENDAR ONLY JOB');
    }
}
