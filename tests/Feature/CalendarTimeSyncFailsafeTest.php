<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CalendarEvent;
use App\Models\Setting;
use App\Services\Calendar\CalendarTimeSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * FAILSAFE: a Google Calendar event can only ever write a booking's time when it
 * carries that booking's EXACT reference. This stops the paired-leg corruption
 * where booking 9Y5MDRa took 9Y5MDRb's date because it was bound to the wrong
 * event. Also: calendar writes are OFF entirely unless the office is on the
 * calendar (calendar_autofollow).
 */
class CalendarTimeSyncFailsafeTest extends TestCase
{
    use RefreshDatabase;

    private function bookingWithEvent(string $bookingRef, string $eventRef, Carbon $bookingAt, Carbon $eventAt): Booking
    {
        $booking = Booking::factory()->create([
            'external_reference' => $bookingRef,
            'pickup_at' => $bookingAt,
            'pickup_address' => 'Terminal 2, Manchester',
            'destination_address' => '2 Worrygoose Lane, Rotherham',
        ]);
        CalendarEvent::create([
            'booking_id' => $booking->id,
            'title' => "*Janine Neill MAN (ABDI)*",
            'description' => "Booking Confirmation\nDate & Time: {$eventAt->format('d/m/Y')} - {$eventAt->format('H:i')}\nBooking Reference: {$eventRef}",
            'start_at' => $eventAt,
            'end_at' => $eventAt->copy()->addHour(),
            'timezone' => 'Europe/London',
        ]);

        return $booking->fresh();
    }

    public function test_a_siblings_event_can_never_move_a_bookings_date(): void
    {
        Setting::set('calendar_autofollow', true); // calendar is the source in this test

        // Booking 9Y5MDRa (04 Oct 09:15) wrongly linked to an event carrying 9Y5MDRb (08 Oct).
        $booking = $this->bookingWithEvent('9Y5MDRa', '9Y5MDRb',
            Carbon::parse('2026-10-04 09:15'), Carbon::parse('2026-10-08 09:00'));

        $changed = app(CalendarTimeSync::class)->alignToCalendarSlot($booking);

        $this->assertFalse($changed, 'a sibling event must not align the booking');
        $this->assertSame('2026-10-04 09:15', $booking->fresh()->pickup_at->format('Y-m-d H:i')); // unchanged
    }

    public function test_the_bookings_own_event_still_aligns(): void
    {
        Setting::set('calendar_autofollow', true);

        // Same reference on both: a genuine own-event time correction is allowed.
        $booking = $this->bookingWithEvent('9Y5MDRa', '9Y5MDRa',
            Carbon::parse('2026-10-04 09:15'), Carbon::parse('2026-10-04 10:30'));

        $changed = app(CalendarTimeSync::class)->alignToCalendarSlot($booking);

        $this->assertTrue($changed);
        $this->assertSame('10:30', $booking->fresh()->pickup_at->format('H:i'));
    }

    public function test_the_base_reference_does_not_match_a_suffixed_event(): void
    {
        // Booking "9Y5MDR" must NOT bind to an event carrying "9Y5MDRa".
        $booking = $this->bookingWithEvent('9Y5MDR', '9Y5MDRa',
            Carbon::parse('2026-10-04 09:15'), Carbon::parse('2026-10-08 09:00'));

        $this->assertFalse(app(CalendarTimeSync::class)->alignToCalendarSlot($booking));
        $this->assertSame('09:15', $booking->fresh()->pickup_at->format('H:i'));
    }
}
