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

    public function test_via_stop_is_read_from_the_calendar_when_not_edited(): void
    {
        // A calendar/ETO booking never touched in the app: the via stop lives
        // ONLY on the calendar description ("• Via: …") — the driver must still
        // see it without it being duplicated into a stops row or column.
        $booking = Booking::factory()->create([
            'pickup_address' => 'Wildes Inn, Clowne',
            'destination_address' => '174 Willifield Way, London NW11 6YD',
        ]);
        $booking->calendarEvents()->create([
            'calendar_id' => 'cal', 'title' => 'x', 'location' => 'x',
            'description' => "• Pickup Location: Wildes Inn, Clowne\n"
                ."• Via: Hilton London Heathrow Airport, Terminal 4\n"
                ."• Drop-off Location: 174 Willifield Way, London NW11 6YD",
            'start_at' => now(), 'end_at' => now()->addHour(), 'timezone' => 'Europe/London',
        ]);

        $this->assertSame(
            ['Hilton London Heathrow Airport, Terminal 4'],
            $booking->fresh()->viaStops(),
        );
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

    public function test_edit_form_prefills_passengers_from_the_calendar_not_the_default(): void
    {
        $admin = User::factory()->admin()->create();
        // Column defaulted to 1 on import, but the calendar's real count is 7.
        $booking = $this->calendarBooking(['passengers' => 1]);
        $this->assertSame(7, $booking->passengerCount());

        // The edit form must show 7 (the displayed count), not the raw 1 — so
        // changing another field can't silently overwrite passengers.
        $this->actingAs($admin)->get(route('bookings.edit', $booking))->assertOk()
            ->assertSee('name="passengers" min="1" max="60" value="7"', false);
    }

    public function test_editing_only_the_time_keeps_the_passenger_count(): void
    {
        $admin = User::factory()->admin()->create();
        $vClass = VehicleType::where('slug', 'v-class')->first();
        $booking = $this->calendarBooking(['passengers' => 1, 'vehicle_type_id' => $vClass->id]);

        // Submit what the (fixed) form would: passengers = the displayed 7, new time.
        $this->actingAs($admin)->put(route('bookings.update', $booking), [
            'customer_name' => $booking->displayName() ?: 'Guest',
            'customer_phone' => '07700900123',
            'vehicle_type_id' => $booking->vehicle_type_id,
            'pickup_at' => '2026-12-25T09:15',
            'pickup_address' => $booking->pickup_address,
            'destination_address' => $booking->destination_address,
            'passengers' => 7,
            'payment_method' => 'card',
        ])->assertRedirect();

        $booking = $booking->fresh();
        $this->assertSame(7, $booking->passengerCount());       // still 7, not 1
        $this->assertFalse($booking->fieldEdited('passengers')); // not wrongly stamped
        $this->assertTrue($booking->fieldEdited('pickup_at'));   // the time IS edited
    }

    public function test_editing_the_booking_reference_updates_it(): void
    {
        $admin = User::factory()->admin()->create();
        // A job we created (intake) so the calendar event is ours to rebuild.
        $booking = Booking::factory()->create([
            'source_system' => 'intake',
            'external_reference' => null,
            'pickup_address' => 'Manchester Airport',
            'destination_address' => '19 Horsewood Road S13 9WL',
        ]);

        $this->actingAs($admin)->put(route('bookings.update', $booking), [
            'customer_name' => $booking->displayName() ?: 'Lawrence',
            'customer_phone' => '07868882217',
            'vehicle_type_id' => $booking->vehicle_type_id,
            'pickup_at' => '2026-09-23T15:05',
            'pickup_address' => $booking->pickup_address,
            'destination_address' => $booking->destination_address,
            'passengers' => 2,
            'payment_method' => 'cash',
            'external_reference' => 'Ryanhn',
        ])->assertRedirect();

        $this->assertSame('Ryanhn', $booking->fresh()->external_reference);
    }

    public function test_edited_addresses_and_a_new_stop_show_on_the_booking_page(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->calendarBooking(['passengers' => 2]); // calendar: Manchester Airport → Broad Elms

        $this->actingAs($admin)->put(route('bookings.update', $booking), [
            'customer_name' => $booking->displayName() ?: 'Guest',
            'customer_phone' => '07700900123',
            'vehicle_type_id' => $booking->vehicle_type_id,
            'pickup_at' => '2026-12-25T09:15',
            'pickup_address' => 'Unit A, Brook Park East, Meadow Lane, Shirebrook',
            'destination_address' => 'Hilton London Heathrow Airport, Terminal 4',
            'via_stops' => ['Shirebrook, Mansfield'],
            'passengers' => 2,
            'payment_method' => 'card',
        ])->assertRedirect();

        $booking = $booking->fresh();
        // The booking's own display reflects the edit.
        $this->assertStringContainsString('Meadow Lane', $booking->displayPickupAddress());
        $this->assertStringContainsString('Hilton London Heathrow', $booking->displayDropoffAddress());
        $this->assertContains('Shirebrook, Mansfield', $booking->viaStops());

        // And the booking page shows the edited journey.
        $this->actingAs($admin)->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('Meadow Lane')
            ->assertSee('Hilton London Heathrow')
            ->assertSee('Shirebrook, Mansfield');
    }

    public function test_ribbon_and_waiting_tickboxes_save_and_flag_the_driver(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->calendarBooking(['passengers' => 2]);

        $this->actingAs($admin)->put(route('bookings.update', $booking), [
            'customer_name' => $booking->displayName() ?: 'Guest',
            'customer_phone' => '07700900123',
            'vehicle_type_id' => $booking->vehicle_type_id,
            'pickup_at' => '2026-12-25T09:15',
            'pickup_address' => $booking->pickup_address,
            'destination_address' => $booking->destination_address,
            'passengers' => 2,
            'payment_method' => 'card',
            'ribbon' => '1',
            'waiting' => '1', 'waiting_where' => 'pickup', 'waiting_minutes' => '20',
        ])->assertRedirect();

        $booking = $booking->fresh();
        $this->assertTrue($booking->isRibbonJob());
        $this->assertTrue($booking->hasWaitingTime());
        $this->assertSame('20 min at pickup', $booking->waitingTimeLabel());

        // Both flags reach the driver's job offer.
        $booking->forceFill(['meta' => array_merge($booking->meta ?? [], ['payroll' => ['pay' => 90]])])->save();
        $offer = $booking->fresh()->driverOfferMessage();
        $this->assertStringContainsString('🎀 Ribbon job', $offer);
        $this->assertStringContainsString('⏳ Waiting time: 20 min at pickup', $offer);
    }

    public function test_unticking_the_tickboxes_clears_them(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->calendarBooking(['passengers' => 2]);
        $booking->forceFill(['meta' => array_merge($booking->meta ?? [], [
            'ribbon' => true, 'waiting_time' => ['where' => 'stop', 'minutes' => 10],
        ])])->save();
        $this->assertTrue($booking->fresh()->isRibbonJob());

        // Submit with the boxes off (hidden 0 inputs carry through).
        $this->actingAs($admin)->put(route('bookings.update', $booking), [
            'customer_name' => 'Guest', 'customer_phone' => '07700900123',
            'vehicle_type_id' => $booking->vehicle_type_id,
            'pickup_at' => '2026-12-25T09:15',
            'pickup_address' => $booking->pickup_address,
            'destination_address' => $booking->destination_address,
            'passengers' => 2, 'payment_method' => 'card',
            'ribbon' => '0', 'waiting' => '0',
        ])->assertRedirect();

        $booking = $booking->fresh();
        $this->assertFalse($booking->isRibbonJob());
        $this->assertNull($booking->waitingTimeInfo());
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
        $this->assertStringContainsString('message your driver directly', $body);
        $this->assertStringNotContainsString('drop us a message', $body);
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

    public function test_foreign_number_return_airport_reminder_routes_through_the_office(): void
    {
        // A non-UK number can't be bridged by the masked line and won't match on
        // WhatsApp, so a return (inbound) airport job must send them to the office.
        $booking = $this->calendarBooking(['flight_number' => 'EZY2104', 'is_return_leg' => true, 'journey_type' => 'return']);
        $booking->customer->update(['phone' => '+33 6 12 34 56 78']); // French mobile
        $booking = $booking->fresh();

        $this->assertTrue($booking->customerHasForeignNumber());

        $body = app(BookingNotifier::class)->reminderBody($booking);
        $this->assertStringContainsString('drop us a message', $body);
        $this->assertStringContainsString('baggage', $body);
        $this->assertStringNotContainsString('message your driver directly', $body);
    }

    public function test_a_uk_number_return_airport_reminder_is_unchanged(): void
    {
        $booking = $this->calendarBooking(['flight_number' => 'EZY2104', 'is_return_leg' => true, 'journey_type' => 'return']);
        $booking->customer->update(['phone' => '07700900123']); // UK
        $booking = $booking->fresh();

        $this->assertFalse($booking->customerHasForeignNumber());

        $body = app(BookingNotifier::class)->reminderBody($booking);
        $this->assertStringContainsString('message your driver directly', $body);
        $this->assertStringNotContainsString('drop us a message', $body);
    }

    public function test_a_foreign_number_on_the_outbound_leg_is_not_routed_through_the_office(): void
    {
        // Outbound (drop-off at the airport) — not a "landed" leg, so no office note
        // even with a foreign number.
        $booking = $this->calendarBooking(['flight_number' => 'EZY2104', 'is_return_leg' => false]);
        $booking->customer->update(['phone' => '+33 6 12 34 56 78']);
        $booking = $booking->fresh();

        $body = app(BookingNotifier::class)->reminderBody($booking);
        $this->assertStringNotContainsString('drop us a message', $body);
    }

    public function test_a_multi_car_reminder_lists_every_car(): void
    {
        $lead = User::factory()->driver()->create(['name' => 'Arfan Khan']);
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Accepted,
            'driver_id' => $lead->id,
            'pickup_at' => now()->addDay(),
        ]);
        $booking->forceFill(['meta' => [
            'driver_details' => ['name' => 'Arfan Khan', 'phone' => '07700900001', 'reg' => 'MT68AVG'],
            'extra_drivers' => [
                ['token' => 'x1', 'name' => 'Sam Jones', 'phone' => '07700900002', 'reg' => 'AB19XYZ', 'car' => 'Black Mercedes V Class', 'status' => 'allocated'],
            ],
        ]])->save();

        $body = app(BookingNotifier::class)->reminderBody($booking->fresh());

        $this->assertStringContainsString('2 cars', $body);
        $this->assertStringContainsString('*Car 1*', $body);
        $this->assertStringContainsString('*Car 2*', $body);
        $this->assertStringContainsString('Arfan', $body);   // first name only
        $this->assertStringContainsString('Sam', $body);
        $this->assertStringContainsString('AB19XYZ', $body);
        $this->assertStringNotContainsString('Khan', $body);  // surname withheld
        // ONLY the lead driver's number goes out; the extra car carries none.
        $this->assertStringContainsString('07700900001', $body);
        $this->assertStringNotContainsString('07700900002', $body);
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
