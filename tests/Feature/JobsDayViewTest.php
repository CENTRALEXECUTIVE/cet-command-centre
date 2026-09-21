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
        $booking = Booking::create([
            'reference' => Booking::generateReference(), 'external_reference' => 'ABC123',
            'customer_id' => Customer::create(['name' => 'Rebecca Fielding', 'phone' => '07889375142'])->id,
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->first()->id,
            'pickup_at' => now()->setTime(6, 0), 'pickup_address' => '81 Hallam Grange Road, Sheffield',
            'destination_address' => 'Manchester Airport (MAN)', 'passengers' => 3,
            'status' => 'pending', 'payment_method' => 'card',
        ]);
        CalendarEvent::create([
            'booking_id' => $booking->id, 'calendar_id' => 'admin@centralexecutivetransfers.co.uk',
            'title' => '*Rebecca Fielding MAN (MAJ)*',
            'description' => "📑 *Booking Confirmation – Departure*\n• *Customer Name:* Rebecca Fielding\n• *Passengers:* 3\n• *Flight Number:* LS919\n• *Payment:* Paid £100 (Square)\n• *Booking Reference:* ABC123",
            'start_at' => now()->setTime(6, 0), 'end_at' => now()->setTime(7, 0),
            'timezone' => 'Europe/London', 'sync_status' => 'synced',
        ]);

        // Calendar unconfigured in tests → DB fallback path.
        $this->actingAs($admin)->get(route('jobs.day'))
            ->assertOk()
            ->assertSee('Rebecca Fielding')
            ->assertSee('Flight Number:', false)   // full detail from the event
            ->assertSee('LS919')
            ->assertSee('Paid £100 (Square)')
            ->assertSee('ABC123');
    }

    public function test_day_view_navigates_by_date(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('jobs.day', ['date' => '2026-07-20']))
            ->assertOk()->assertSee('20 July 2026');
    }

    public function test_every_booking_on_the_day_is_listed_none_dropped(): void
    {
        $admin = User::factory()->admin()->create();
        $exec = VehicleType::where('slug', 'executive')->first()->id;
        $day = \Illuminate\Support\Carbon::create(2026, 7, 20, 12, 0);

        // Three jobs that day: one with a calendar event, one with NO event,
        // and one whose event title is NOT in CET format. None must be hidden.
        foreach ([
            ['Alice Adams', '2026-07-20 08:00', true, '*Alice Adams MAN (MAJ)*'],
            ['Bob Barker', '2026-07-20 10:30', false, null],
            ['Carol Clark', '2026-07-20 14:15', true, 'Carol pickup — no stars'],
        ] as [$name, $at, $withEvent, $title]) {
            $booking = Booking::create([
                'reference' => Booking::generateReference(),
                'customer_id' => Customer::create(['name' => $name])->id,
                'vehicle_type_id' => $exec,
                'pickup_at' => $at, 'pickup_address' => 'Sheffield',
                'destination_address' => 'Manchester Airport', 'passengers' => 1,
                'status' => 'pending', 'payment_method' => 'card',
            ]);
            if ($withEvent) {
                CalendarEvent::create([
                    'booking_id' => $booking->id, 'title' => $title,
                    'start_at' => $at, 'end_at' => $day, 'sync_status' => 'synced',
                ]);
            }
        }

        $this->actingAs($admin)->get(route('jobs.day', ['date' => '2026-07-20']))
            ->assertOk()
            ->assertSee('3 job(s)')
            ->assertSee('Alice Adams')
            ->assertSee('Bob Barker')
            ->assertSee('Carol Clark');
    }
}
