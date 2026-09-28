<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CalendarEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * THE OFFICE IS THE BOSS for via stops: once the office has edited the via list in
 * the app, that list wins over the calendar/import — including when the office has
 * REMOVED a via that still exists on the calendar. A via edit must never be undone.
 */
class ViaStopStickinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\VehicleTypeSeeder::class);
    }

    private function bookingWithCalendarVia(): Booking
    {
        $booking = Booking::factory()->create([
            'pickup_at' => now()->addDays(2)->setTime(12, 0),
            'external_reference' => 'VIA123A',
            'pickup_address' => 'Manchester Airport T2',
            'destination_address' => 'Sheffield',
        ]);
        CalendarEvent::create([
            'booking_id' => $booking->id,
            'calendar_id' => 'admin@centralexecutivetransfers.co.uk',
            'google_event_id' => 'evt_via',
            'title' => '*Test DON (COVER)*',
            'location' => 'Manchester Airport T2',
            'description' => "📑 *Booking Confirmation*\n• *Via:* Manchester Airport T3\n• *Pickup Location:* Manchester Airport T2",
            'start_at' => $booking->pickup_at,
            'end_at' => $booking->pickup_at->copy()->addHour(),
            'sync_status' => 'synced',
        ]);

        return $booking->fresh(['calendarEvent', 'stops']);
    }

    public function test_calendar_via_shows_when_not_edited(): void
    {
        $booking = $this->bookingWithCalendarVia();
        $this->assertSame(['Manchester Airport T3'], $booking->viaStops());
    }

    public function test_an_edited_via_list_wins_over_the_calendar(): void
    {
        $booking = $this->bookingWithCalendarVia();
        $booking->stops()->create(['sequence' => 1, 'address' => 'Manchester Airport T5']);
        $booking->forceFill(['meta' => array_merge($booking->meta ?? [], ['edited_fields' => ['via_stops']])])->save();

        $this->assertSame(['Manchester Airport T5'], $booking->fresh(['stops', 'calendarEvent'])->viaStops());
    }

    public function test_removing_a_via_sticks_even_though_the_calendar_still_has_it(): void
    {
        $booking = $this->bookingWithCalendarVia();
        // Office cleared all vias: empty stops table + via_stops marked edited.
        $booking->forceFill(['meta' => array_merge($booking->meta ?? [], ['edited_fields' => ['via_stops']])])->save();

        $this->assertSame([], $booking->fresh(['stops', 'calendarEvent'])->viaStops());
    }

    public function test_removing_a_via_persists_even_on_a_return_leg(): void
    {
        $exec = \App\Models\VehicleType::where('slug', 'executive')->firstOrFail();
        $booking = $this->bookingWithCalendarVia();
        $booking->forceFill(['is_return_leg' => true, 'vehicle_type_id' => $exec->id])->save();
        $booking->stops()->create(['sequence' => 1, 'address' => 'Manchester Airport T3']);

        // Office clears the via on the return leg (submits an empty via list).
        app(\App\Services\BookingService::class)->updateFromForm($booking->fresh(['stops', 'calendarEvent', 'customer']), [
            'customer_name' => 'Cx',
            'vehicle_type_id' => $exec->id,
            'pickup_at' => $booking->pickup_at->format('Y-m-d\TH:i'),
            'pickup_address' => 'Manchester Airport T2',
            'destination_address' => 'Sheffield',
            'passengers' => 2,
            'payment_method' => 'cash',
            'via_stops' => [''], // removed
        ]);

        $fresh = $booking->fresh(['stops', 'calendarEvent']);
        $this->assertSame(0, $fresh->stops()->count());
        $this->assertSame([], $fresh->viaStops());
    }

    public function test_editing_a_via_marks_it_and_sticks(): void
    {
        $exec = \App\Models\VehicleType::where('slug', 'executive')->firstOrFail();
        $booking = $this->bookingWithCalendarVia();
        $booking->update(['vehicle_type_id' => $exec->id]);

        // The office changes the via from the calendar's T3 to T5.
        app(\App\Services\BookingService::class)->updateFromForm($booking->fresh(['stops', 'calendarEvent', 'customer']), [
            'customer_name' => 'Cx',
            'vehicle_type_id' => $exec->id,
            'pickup_at' => $booking->pickup_at->format('Y-m-d\TH:i'),
            'pickup_address' => 'Manchester Airport T2',
            'destination_address' => 'Sheffield',
            'passengers' => 2,
            'payment_method' => 'cash',
            'via_stops' => ['Manchester Airport T5'],
        ]);

        $fresh = $booking->fresh(['stops', 'calendarEvent']);
        $this->assertTrue($fresh->fieldEdited('via_stops'));
        $this->assertSame(['Manchester Airport T5'], $fresh->viaStops());
    }
}
