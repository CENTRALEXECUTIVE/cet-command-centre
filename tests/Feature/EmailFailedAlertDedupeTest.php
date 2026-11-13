<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\WatchdogEvent;
use App\Support\MailFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A retried email that keeps failing raises ONE alert, not one every 5 minutes. */
class EmailFailedAlertDedupeTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeat_failures_of_the_same_email_raise_one_alert(): void
    {
        $customer = Customer::create(['name' => 'Al Ert', 'phone' => '07700900991']);
        $booking = Booking::factory()->create(['customer_id' => $customer->id, 'pickup_at' => now()->addDay()]);

        foreach (range(1, 5) as $i) {
            MailFailure::report('office booking email (paid)', new \RuntimeException('Microsoft down'), $booking);
        }
        $this->assertSame(1, WatchdogEvent::where('event_type', 'email_failed')->count());

        // A different email for the same booking still gets its own alert.
        MailFailure::report('customer booking confirmation', new \RuntimeException('down'), $booking);
        $this->assertSame(2, WatchdogEvent::where('event_type', 'email_failed')->count());
    }

    public function test_the_tidy_ticks_off_repeats_and_keeps_the_newest(): void
    {
        foreach (range(1, 4) as $i) {
            WatchdogEvent::log('email_failed', 'Same alert', 'warning');
        }
        (require base_path('database/migrations/2026_11_13_000002_tidy_repeated_email_failed_alerts.php'))->up();

        $this->assertSame(1, WatchdogEvent::where('event_type', 'email_failed')->whereNull('acknowledged_at')->count());
        $this->assertSame(WatchdogEvent::max('id'), WatchdogEvent::whereNull('acknowledged_at')->value('id'));
    }
}
