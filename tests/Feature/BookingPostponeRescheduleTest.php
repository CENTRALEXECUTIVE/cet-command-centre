<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Postpone / Reschedule — the "customer's flight was cancelled, they'll rebook"
 * flow. Postpone parks the booking (off every live list, reminders dropped,
 * masked line closed) WITHOUT refunding — the record and payment are held.
 * Reschedule carries that payment onto a new date and reinstates the job.
 */
class BookingPostponeRescheduleTest extends TestCase
{
    use RefreshDatabase;

    private function allocatedBooking(): Booking
    {
        $driver = User::factory()->create(['role' => 'driver']);

        return Booking::factory()->create([
            'status' => BookingStatus::Allocated,
            'driver_id' => $driver->id,
            'pickup_at' => now()->addDays(2)->setTime(9, 0),
            'quoted_price' => 150.00,
            'payment_status' => 'deposit_paid',
        ]);
    }

    public function test_postpone_parks_the_booking_and_holds_the_payment(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->allocatedBooking();

        // A queued reminder that must be cleared when the job is parked.
        Message::create([
            'booking_id' => $booking->id, 'customer_id' => $booking->customer_id,
            'channel' => 'whatsapp', 'direction' => 'outbound', 'type' => 'reminder_24h',
            'status' => 'queued', 'body' => 'x', 'scheduled_for' => now()->addDay(),
        ]);

        $this->actingAs($admin)
            ->post(route('bookings.postpone', $booking), ['postpone_reason' => 'Flight cancelled — rebooking'])
            ->assertRedirect(route('bookings.show', $booking));

        $booking->refresh();

        $this->assertTrue($booking->isPostponed());
        $this->assertSame('Postponed', $booking->statusLabel());
        $this->assertNull($booking->driver_id, 'the driver is freed');
        // Payment is HELD, not refunded.
        $this->assertSame('150.00', (string) $booking->quoted_price);
        $this->assertSame('deposit_paid', $booking->payment_status);
        // Queued reminder dropped — it must not sit in the office worklist.
        $this->assertSame(0, $booking->messages()->where('type', 'reminder_24h')->where('status', 'queued')->count());
        // It is NOT a real cancellation.
        $this->assertNull($booking->meta['cancellation_reason'] ?? null);
        $this->assertSame('Flight cancelled — rebooking', $booking->meta['postpone_reason']);
    }

    public function test_reschedule_carries_the_payment_onto_a_new_date_and_reinstates(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->allocatedBooking();

        $this->actingAs($admin)->post(route('bookings.postpone', $booking), []);
        $booking->refresh();
        $this->assertTrue($booking->isPostponed());

        $newPickup = now()->addDays(10)->setTime(14, 30);
        $this->actingAs($admin)
            ->post(route('bookings.reschedule', $booking), [
                'pickup_at' => $newPickup->format('Y-m-d\TH:i'),
                'flight_number' => 'BA1234',
            ])
            ->assertRedirect(route('bookings.show', $booking));

        $booking->refresh();

        $this->assertFalse($booking->isPostponed());
        $this->assertSame(BookingStatus::Pending, $booking->status, 'back in the live flow');
        $this->assertSame($newPickup->format('Y-m-d H:i'), $booking->pickup_at->format('Y-m-d H:i'));
        // UK-local wall time — not shifted by a timezone conversion.
        $this->assertSame('14:30', $booking->pickup_at->format('H:i'));
        $this->assertSame('BA1234', $booking->flight_number);
        // Payment still held — nothing re-charged.
        $this->assertSame('150.00', (string) $booking->quoted_price);
    }

    public function test_postpone_and_reschedule_are_admin_only(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $booking = $this->allocatedBooking();

        $this->actingAs($driver)->post(route('bookings.postpone', $booking), [])->assertForbidden();
        $this->actingAs($driver)->post(route('bookings.reschedule', $booking), [
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
        ])->assertForbidden();
    }

    public function test_show_page_offers_postpone_and_then_reschedule(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->allocatedBooking();

        // Live job: the Postpone button is offered.
        $this->actingAs($admin)->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee(route('bookings.postpone', $booking), false);

        // Once postponed: the Reschedule form is offered, not a "Cancelled" banner.
        $this->actingAs($admin)->post(route('bookings.postpone', $booking), []);
        $this->actingAs($admin)->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee(route('bookings.reschedule', $booking), false)
            ->assertSee('Postponed — awaiting reschedule', false);
    }
}
