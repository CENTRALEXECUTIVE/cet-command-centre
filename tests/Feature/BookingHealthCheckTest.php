<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingAttempt;
use App\Models\Customer;
use App\Models\WatchdogEvent;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** The 15-minute test customer (27 Nov 2026): books through the real form, then undoes it. */
class BookingHealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_working_form_passes_and_leaves_nothing_behind(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        Mail::fake();

        $this->artisan('cet:booking-health-check')->assertSuccessful();

        $this->assertSame(0, Booking::count(), 'no test booking is left behind');
        $this->assertSame(0, Customer::count());
        $this->assertSame(0, BookingAttempt::count());
        $this->assertSame(0, WatchdogEvent::count(), 'no alert reaches anyone');
        Mail::assertNothingSent();
    }

    public function test_a_broken_form_alerts_the_directors(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        Mail::fake();
        // Simulate the form breaking: saving a customer fails.
        Customer::creating(fn () => throw new \RuntimeException('database down'));

        $this->artisan('cet:booking-health-check')->assertFailed();

        $this->assertSame(0, Booking::count());
        $ev = WatchdogEvent::where('severity', 'critical')->latest('id')->first();
        $this->assertNotNull($ev, 'the directors are alerted');
        $this->assertStringContainsString('booking form is FAILING', $ev->title);
    }

    public function test_it_is_scheduled_every_15_minutes(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'cet:booking-health-check'));
        $this->assertCount(1, $events);
        $this->assertSame('*/15 * * * *', $events->first()->expression);
    }
}
