<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Support\MailFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Email is business-critical, so a send failure must never be silent — it's
 * surfaced on the office alerts feed (watchdog_events), not just a log line.
 */
class MailFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_failed_send_raises_a_warning_on_the_alerts_feed(): void
    {
        $booking = Booking::factory()->create(['external_reference' => 'ABC123']);

        MailFailure::report('payment link email', new \RuntimeException('smtp down'), $booking);

        $this->assertDatabaseHas('watchdog_events', [
            'event_type' => 'email_failed',
            'booking_id' => $booking->id,
            'severity' => 'warning',
        ]);
    }

    public function test_it_never_throws_without_a_booking(): void
    {
        MailFailure::report('office booking email', new \RuntimeException('nope'), null);

        $this->assertDatabaseHas('watchdog_events', ['event_type' => 'email_failed']);
    }
}
