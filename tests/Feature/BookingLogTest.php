<?php

namespace Tests\Feature;

use App\Models\BookingAttempt;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Every website booking attempt is recorded silently (26 Nov 2026). */
class BookingLogTest extends TestCase
{
    use RefreshDatabase;

    private function base(array $o = []): array
    {
        return array_merge([
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH',
            'destination_address' => 'Rotherham S60 5WG', 'destination_postcode' => 'S60 5WG',
            'pickup_at' => now()->addDays(10)->format('Y-m-d\TH:i'),
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->value('id'), 'passengers' => 1,
            'customer_first_name' => 'Log', 'customer_surname' => 'Tester',
            'customer_phone' => '07700900777', 'customer_email' => 'log@example.com',
        ], $o);
    }

    public function test_booked_refused_and_on_request_are_all_recorded_and_shown_to_admins(): void
    {
        $this->seed(VehicleTypeSeeder::class);

        $this->post(route('widget.book.store'), $this->base())->assertOk();
        $this->assertSame('booked', BookingAttempt::latest('id')->first()->outcome);
        $this->assertNotNull(BookingAttempt::latest('id')->first()->reference);

        $this->post(route('widget.book.store'), $this->base(['customer_phone' => '', 'customer_email' => 'log@example.com', 'pickup_at' => now()->subDay()->format('Y-m-d\TH:i')]));
        $refused = BookingAttempt::latest('id')->first();
        $this->assertSame('refused', $refused->outcome);
        $this->assertNotEmpty($refused->details['errors']);

        $rr = VehicleType::where('slug', 'rolls-royce-ghost')->first();
        $this->postJson(route('widget.prices'), ['pickup' => 'Manchester Airport (MAN)', 'destination' => 'Sheffield S1 2HH', 'pickup_at' => now()->addDays(10)->format('Y-m-d\TH:i')])->assertOk();
        // Only the Rolls-Royce is "On request" here: that's normal, so nothing is logged.
        $this->assertSame(0, BookingAttempt::where('outcome', 'price_on_request')->count());

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('booking-log.index', ['show' => 'all']))->assertOk()->assertSee('Log Tester');
        $driver = User::factory()->create();
        $this->actingAs($driver)->get(route('booking-log.index'))->assertForbidden();
    }

    public function test_a_standard_car_showing_on_request_alerts_the_directors_live_but_rolls_royce_does_not(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        $m = \Mockery::mock(\App\Services\Pricing\DistanceService::class)->makePartial();
        $m->shouldReceive('configured')->andReturn(true);
        $m->shouldReceive('resolve')->andReturn(['miles' => 10, 'minutes' => 20, 'source' => 'estimate']);
        $this->app->instance(\App\Services\Pricing\DistanceService::class, $m);

        // A Rolls-Royce-only "On request" (a normal Sheffield → Rotherham trip) is not alerted.
        $this->postJson(route('widget.prices'), ['pickup' => 'Sheffield S1 2HH', 'destination' => 'Manchester Airport (MAN)'])->assertOk();
        $this->assertSame(0, \App\Models\WatchdogEvent::where('event_type', 'price_on_request')->count());

        // An address Google can't place makes the normal cars "On request": alert, once.
        $this->postJson(route('widget.prices'), ['pickup' => 'Manchester Airport (MAN)', 'destination' => '12 Nowhere Lane'])->assertOk();
        $this->postJson(route('widget.prices'), ['pickup' => 'Manchester Airport (MAN)', 'destination' => '12 Nowhere Lane'])->assertOk();
        $ev = \App\Models\WatchdogEvent::where('event_type', 'price_on_request')->get();
        $this->assertCount(1, $ev);
        $this->assertSame('critical', $ev->first()->severity);
    }
}
