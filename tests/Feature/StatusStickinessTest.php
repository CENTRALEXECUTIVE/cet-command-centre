<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Services\Inbox\OutlookBookingService;
use App\Services\BookingStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The office is the boss: a status a person set in the app must STICK. The
 * automated ETO email ingestion must never silently revert it — e.g. re-cancel a
 * booking the office put back to allocated by re-reading the same cancellation
 * email. Instead it leaves the office's status alone and flags it for review.
 */
class StatusStickinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_in_app_status_change_locks_the_status(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create();
        $b = Booking::factory()->create(['status' => BookingStatus::Pending->value]);

        app(BookingStatusService::class)->allocateDriver($b, $driver, $admin);

        $this->assertTrue($b->fresh()->statusManuallyLocked());
    }

    private function etoBooking(string $ref, BookingStatus $status, bool $locked): Booking
    {
        return Booking::factory()->create([
            'source_system' => 'eto',
            'external_reference' => $ref,
            'status' => $status->value,
            'meta' => $locked ? ['status_locked_at' => now()->toIso8601String(), 'status_locked_to' => $status->value] : [],
        ]);
    }

    public function test_a_reread_cancellation_does_not_revert_an_office_set_status(): void
    {
        // Office cancelled then put it back to allocated (locked, not cancelled).
        $b = $this->etoBooking('LOCK1', BookingStatus::Allocated, locked: true);

        $result = app(OutlookBookingService::class)
            ->upsertFromParsed(['is_booking' => true, 'cancelled' => true, 'reference' => 'LOCK1']);

        $this->assertNull($result); // not acted on
        $this->assertSame(BookingStatus::Allocated, $b->fresh()->status); // NOT reverted
        $this->assertNotEmpty($b->fresh()->meta['eto_cancel_ignored_at']); // flagged for the office
        $this->assertDatabaseHas('watchdog_events', ['booking_id' => $b->id, 'event_type' => 'eto_cancel_ignored']);
    }

    public function test_a_genuine_cancellation_of_an_untouched_booking_still_applies(): void
    {
        // No office status change → not locked → ETO cancellation applies as before.
        $b = $this->etoBooking('OPEN1', BookingStatus::Pending, locked: false);

        $result = app(OutlookBookingService::class)
            ->upsertFromParsed(['is_booking' => true, 'cancelled' => true, 'reference' => 'OPEN1']);

        $this->assertSame('cancelled', $result['action']);
        $this->assertSame(BookingStatus::Cancelled, $b->fresh()->status);
    }
}
