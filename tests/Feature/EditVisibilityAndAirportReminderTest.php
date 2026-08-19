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

    public function test_airport_pickup_reminder_asks_the_customer_to_text_landed(): void
    {
        $booking = $this->calendarBooking(['flight_number' => 'EZY2104']);

        $body = app(BookingNotifier::class)->reminderBody($booking->fresh());

        $this->assertStringContainsString('LANDED', $body);
        $this->assertStringContainsString('EZY2104', $body);
        $this->assertStringContainsString('delayed', $body);
    }

    public function test_a_non_airport_pickup_has_no_landed_instructions(): void
    {
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Accepted,
            'pickup_address' => '5 Ecclesall Road, Sheffield',
            'destination_address' => 'Meadowhall, Sheffield',
        ]);

        $body = app(BookingNotifier::class)->reminderBody($booking->fresh());

        $this->assertStringNotContainsString('LANDED', $body);
    }
}
