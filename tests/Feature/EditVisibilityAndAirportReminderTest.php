<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\Messaging\BookingNotifier;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * (1) A manual edit must actually show — the operator's values win over the
 *     calendar once a booking has been edited in the app.
 * (2) Airport pick-ups get "text us LANDED" instructions on the reminder.
 */
class EditVisibilityAndAirportReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function calendarBooking(array $attrs = []): Booking
    {
        $booking = Booking::factory()->create(array_merge([
            'status' => BookingStatus::Accepted,
            'pickup_address' => 'Manchester Airport M90 1QX',
            'destination_address' => '22 Broad Elms Lane, Sheffield',
            'passengers' => 7,
        ], $attrs));
        $booking->calendarEvents()->create([
            'calendar_id' => 'cal', 'title' => 'x', 'location' => 'x',
            'description' => "• Passengers: 7\n• Pickup Location: Manchester Airport M90 1QX\n"
                ."• Drop-off Location: 22 Broad Elms Lane, Sheffield\n• Vehicle Type: Minibus",
            'start_at' => now(), 'end_at' => now()->addHour(), 'timezone' => 'Europe/London',
        ]);

        return $booking->fresh();
    }

    public function test_without_an_edit_the_calendar_value_is_shown(): void
    {
        $booking = $this->calendarBooking();
        $booking->forceFill(['destination_address' => 'CHANGED IN DB ONLY'])->save();

        // No manual-edit marker → the calendar wins (source of truth).
        $this->assertSame('22 Broad Elms Lane, Sheffield', $booking->fresh()->displayDropoffAddress());
    }

    public function test_a_manual_edit_wins_over_the_calendar(): void
    {
        $booking = $this->calendarBooking();
        $booking->forceFill([
            'destination_address' => '10 New Road, Barnsley',
            'passengers' => 4,
            'meta' => array_merge($booking->meta ?? [], ['manually_edited_at' => now()->toIso8601String()]),
        ])->save();

        $booking = $booking->fresh();
        $this->assertSame('10 New Road, Barnsley', $booking->displayDropoffAddress());
        $this->assertSame(4, $booking->passengerCount());
        // A field the operator didn't change still falls back to the calendar.
        $this->assertSame('Manchester Airport M90 1QX', $booking->displayPickupAddress());
    }

    public function test_editing_a_booking_does_not_blank_luggage_it_still_mirrors_the_calendar(): void
    {
        // A calendar-sourced booking with 0/0 stored counts (luggage never
        // captured). The calendar says 2 + 2. Editing it for another reason
        // (allocating a driver) sets the manual-edit marker — but that must NOT
        // blank the luggage down to "0 · 0"; the calendar still wins.
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Accepted,
            'passengers' => 1, 'luggage' => 0,
            'meta' => ['suitcases' => 0, 'hand_luggage' => 0],
        ]);
        $booking->calendarEvents()->create([
            'calendar_id' => 'cal', 'title' => '*Claire MAN Return (COVER)*', 'location' => 'x',
            'description' => "• Customer Name: Claire\n• Luggage: 2 Suitcases and 2 Hand Luggage\n• Vehicle Type: Executive",
            'start_at' => now(), 'end_at' => now()->addHour(), 'timezone' => 'Europe/London',
        ]);

        // Allocated to a cover driver → a manual edit, but luggage untouched.
        $booking->forceFill(['meta' => array_merge($booking->fresh()->meta ?? [], [
            'manually_edited_at' => now()->toIso8601String(),
        ])])->save();
        $booking = $booking->fresh();

        $this->assertTrue($booking->manuallyEdited());
        $this->assertSame('2 Suitcases and 2 Hand Luggage', $booking->luggageShort());
        $this->assertSame('2 Suitcases and 2 Hand Luggage', $booking->luggageBreakdown());
        // The edit form pre-fills the real luggage, so re-saving can't zero it.
        $this->assertSame(2, $booking->displaySuitcases());
        $this->assertSame(2, $booking->displayHandLuggage());
    }

    public function test_editing_only_the_time_through_the_form_records_it_as_edited(): void
    {
        $admin = User::factory()->admin()->create();
        $vt = VehicleType::where('name', 'V Class')->first(); // seats 7
        $original = now()->addDay()->setTime(10, 20)->setSeconds(0);
        $booking = $this->calendarBooking(['pickup_at' => $original]);

        // Resubmit everything unchanged EXCEPT the pickup time (10:20 → 12:45).
        $this->actingAs($admin)->put(route('bookings.update', $booking->fresh()), [
            'customer_name' => 'Test', 'customer_phone' => '07700900000',
            'vehicle_type_id' => $vt->id,
            'pickup_at' => $original->copy()->setTime(12, 45)->format('Y-m-d\TH:i'),
            'pickup_address' => 'Manchester Airport M90 1QX',
            'destination_address' => '22 Broad Elms Lane, Sheffield',
            'passengers' => 7, 'payment_method' => 'cash', 'journey_type' => 'one_way',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $booking = $booking->fresh();
        $this->assertContains('pickup_at', $booking->meta['edited_fields']);
        $this->assertSame('12:45', $booking->pickup_at->format('H:i'));
        $this->assertTrue($booking->fieldEdited('pickup_at'));
    }

    public function test_a_time_edited_in_cet_is_not_overwritten_by_the_calendar_sync(): void
    {
        // The calendar slot says 09:00; the office edits the pickup to 10:30 in
        // CET. A later live calendar align must NOT drag it back to 09:00 —
        // per-field: the CET edit wins.
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Accepted,
            'pickup_at' => now()->addDay()->setTime(10, 30),
            'meta' => [
                'manually_edited_at' => now()->toIso8601String(),
                'edited_fields' => ['pickup_at'],
            ],
        ]);
        $booking->calendarEvents()->create([
            'calendar_id' => 'cal', 'title' => 'x', 'location' => 'x',
            'description' => "• Date and Time: ".now()->addDay()->format('d/m/Y')." – 09:00",
            'start_at' => now()->addDay()->setTime(9, 0),
            'end_at' => now()->addDay()->setTime(10, 0), 'timezone' => 'Europe/London',
        ]);
        $booking = $booking->fresh();

        app(\App\Services\Calendar\CalendarTimeSync::class)->alignToCalendarSlot($booking);

        $this->assertSame('10:30', $booking->fresh()->pickup_at->format('H:i'));
    }

    public function test_a_real_luggage_edit_still_wins_over_the_calendar(): void
    {
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Accepted, 'passengers' => 3,
            'meta' => ['suitcases' => 4, 'hand_luggage' => 1, 'manually_edited_at' => now()->toIso8601String()],
        ]);
        $booking->calendarEvents()->create([
            'calendar_id' => 'cal', 'title' => 'x', 'location' => 'x',
            'description' => "• Luggage: 2 Suitcases and 2 Hand Luggage",
            'start_at' => now(), 'end_at' => now()->addHour(), 'timezone' => 'Europe/London',
        ]);
        $booking = $booking->fresh();

        // The office genuinely entered 4 + 1 — that wins over the calendar's 2 + 2.
        $this->assertSame('4 cases · 1 hand', $booking->luggageShort());
    }

    public function test_saving_an_edit_through_the_form_marks_it_and_shows(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->calendarBooking(['pickup_at' => now()->addDay()]);
        $vt = VehicleType::first();

        $this->actingAs($admin)->put(route('bookings.update', $booking), [
            'customer_name' => 'Test', 'customer_phone' => '07700900000',
            'vehicle_type_id' => $vt->id,
            'pickup_at' => $booking->pickup_at->format('Y-m-d\TH:i'),
            'pickup_address' => 'Manchester Airport M90 1QX',
            'destination_address' => '99 Edited Street, Rotherham',
            'passengers' => 3, 'payment_method' => 'cash', 'journey_type' => 'one_way',
        ])->assertRedirect();

        $booking = $booking->fresh();
        $this->assertTrue($booking->manuallyEdited());
        $this->assertSame('99 Edited Street, Rotherham', $booking->displayDropoffAddress());
        $this->assertSame(3, $booking->passengerCount());
    }

    public function test_editing_one_field_leaves_every_other_field_matching_the_calendar(): void
    {
        // The calendar carries passengers 7, a Minibus, and 2+2 luggage. The
        // office edits ONLY the drop-off. Every OTHER field must keep mirroring
        // the calendar — not fall back to a stored default/blank.
        $admin = User::factory()->admin()->create();
        $pickupAt = now()->addDay()->setTime(11, 50)->setSeconds(0);
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Accepted,
            'pickup_at' => $pickupAt,
            'pickup_address' => 'Manchester Airport M90 1QX',
            'destination_address' => '22 Broad Elms Lane, Sheffield',
            'passengers' => 1, 'luggage' => 0,
            'meta' => ['suitcases' => 0, 'hand_luggage' => 0],
        ]);
        $vt = VehicleType::where('name', 'V Class')->first(); // capacity 7, exact name
        $booking->calendarEvents()->create([
            'calendar_id' => 'cal', 'title' => 'x', 'location' => 'x',
            'description' => "• Customer Name: Claire\n• Passengers: 7\n"
                ."• Luggage: 2 Suitcases and 2 Hand Luggage\n"
                ."• Pickup Location: Manchester Airport M90 1QX\n"
                ."• Drop-off Location: 22 Broad Elms Lane, Sheffield\n• Vehicle Type: V Class",
            'start_at' => now(), 'end_at' => now()->addHour(), 'timezone' => 'Europe/London',
        ]);

        $this->actingAs($admin)->put(route('bookings.update', $booking->fresh()), [
            'customer_name' => 'Claire', 'customer_phone' => '07700900000',
            'vehicle_type_id' => $vt->id,
            'pickup_at' => $pickupAt->format('Y-m-d\TH:i'), // resubmitted unchanged
            'pickup_address' => 'Manchester Airport M90 1QX',
            'destination_address' => '99 Edited Street, Rotherham', // the ONLY change
            'passengers' => 7,                 // resubmitted same as calendar
            'suitcases' => 2, 'hand_luggage' => 2, // resubmitted same as calendar
            'payment_method' => 'cash', 'journey_type' => 'one_way',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $booking = $booking->fresh();
        // The edited field wins…
        $this->assertSame('99 Edited Street, Rotherham', $booking->displayDropoffAddress());
        // …and every untouched field still matches the calendar.
        $this->assertSame(7, $booking->passengerCount());
        $this->assertSame('2 Suitcases and 2 Hand Luggage', $booking->luggageShort());
        $this->assertSame('Manchester Airport M90 1QX', $booking->displayPickupAddress());
        // Only the drop-off is recorded as edited.
        $this->assertSame(['destination_address'], $booking->meta['edited_fields']);
    }

    public function test_an_edit_updates_the_driver_link_automatically(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->calendarBooking(['pickup_at' => now()->addDay()]);
        $vt = VehicleType::first();

        // Driver link shows the calendar value before the edit.
        $this->get(route('driver.link', $booking->driverLinkToken()))
            ->assertOk()->assertSee('22 Broad Elms Lane, Sheffield');

        $this->actingAs($admin)->put(route('bookings.update', $booking), [
            'customer_name' => 'Test', 'customer_phone' => '07700900000',
            'vehicle_type_id' => $vt->id,
            'pickup_at' => $booking->pickup_at->format('Y-m-d\TH:i'),
            'pickup_address' => 'Manchester Airport M90 1QX',
            'destination_address' => '99 Edited Street, Rotherham',
            'passengers' => 3, 'payment_method' => 'cash', 'journey_type' => 'one_way',
        ])->assertRedirect();

        // The driver link now shows the edited drop-off — no calendar change needed.
        $this->get(route('driver.link', $booking->fresh()->driverLinkToken()))
            ->assertOk()
            ->assertSee('99 Edited Street, Rotherham')
            ->assertDontSee('22 Broad Elms Lane, Sheffield');
    }

    public function test_airport_pickup_reminder_asks_the_customer_to_message_when_landed(): void
    {
        $booking = $this->calendarBooking(['flight_number' => 'EZY2104']);

        $body = app(BookingNotifier::class)->reminderBody($booking->fresh());

        $this->assertStringContainsString('landed', $body);
        $this->assertStringContainsString('drop us a message', $body);
    }

    public function test_a_non_airport_pickup_has_no_landed_instructions(): void
    {
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Accepted,
            'pickup_address' => '5 Ecclesall Road, Sheffield',
            'destination_address' => 'Meadowhall, Sheffield',
        ]);

        $body = app(BookingNotifier::class)->reminderBody($booking->fresh());

        $this->assertStringNotContainsString('landed', $body);
    }

    public function test_a_return_airport_reminder_points_the_customer_to_their_driver(): void
    {
        $booking = $this->calendarBooking(['flight_number' => 'EZY2104', 'is_return_leg' => true, 'journey_type' => 'return']);

        $body = app(BookingNotifier::class)->reminderBody($booking->fresh());

        $this->assertStringContainsString('message your driver directly', $body);
        $this->assertStringNotContainsString('drop us a message', $body); // not the office on a return
    }

    public function test_customer_reminder_uses_only_the_drivers_first_name(): void
    {
        $driver = User::factory()->driver()->create(['name' => 'Hamza V Class Khan']);
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Accepted,
            'driver_id' => $driver->id,
            'pickup_at' => now()->addDay(),
        ]);
        $booking->forceFill(['meta' => ['driver_details' => ['name' => 'Hamza V Class Khan', 'phone' => '07700900000']]])->save();

        $body = app(BookingNotifier::class)->reminderBody($booking->fresh());

        $this->assertStringContainsString('Driver Name: Hamza', $body);
        $this->assertStringNotContainsString('V Class', $body);
        $this->assertStringNotContainsString('Khan', $body);
    }
}
