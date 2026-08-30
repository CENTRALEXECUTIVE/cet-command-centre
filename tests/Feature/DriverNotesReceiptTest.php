<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Jobs with office notes: the driver taps to confirm they've READ them (like the
 * child-seat confirm), and the office can see whether it landed.
 */
class DriverNotesReceiptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function notedJob(): Booking
    {
        $driver = User::factory()->driver()->create(['name' => 'Rob']);
        $booking = Booking::factory()
            ->forVehicleType(VehicleType::where('slug', 'executive')->first())
            ->create([
                'customer_id' => Customer::factory()->create(['name' => 'Jo Bloggs'])->id,
                'driver_id' => $driver->id, 'pickup_at' => now()->addDay(),
                'status' => BookingStatus::Allocated->value,
            ]);
        $booking->forceFill(['meta' => array_merge($booking->meta ?? [], ['driver_notes' => 'Ring the buzzer for flat 3'])])->save();

        return $booking->fresh();
    }

    public function test_the_driver_link_shows_notes_with_a_confirm_button_and_records_it(): void
    {
        $booking = $this->notedJob();
        $token = $booking->driverLinkToken();

        $this->get(route('driver.link', $token))->assertOk()
            ->assertSee('Ring the buzzer for flat 3')
            ->assertSee('I’ve read these notes', false);

        $this->post(route('driver.link.notes-ack', $token))->assertRedirect();

        $booking->refresh();
        $this->assertTrue($booking->driverNotesAcknowledged());
        $this->assertNotNull($booking->driverNotesAckAt());

        $this->get(route('driver.link', $token))->assertOk()
            ->assertSee('You’ve confirmed you read these notes', false)
            ->assertDontSee('I’ve read these notes', false);
    }

    public function test_the_admin_booking_page_shows_the_read_receipt(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->notedJob();

        $this->actingAs($admin)->get(route('bookings.show', $booking))->assertOk()
            ->assertSee('notes · not read');

        $booking->confirmDriverNotesRead();
        $this->actingAs($admin)->get(route('bookings.show', $booking->fresh()))->assertOk()
            ->assertSee('notes · read ✓', false);
    }

    public function test_special_requests_also_count_as_notes_to_confirm(): void
    {
        // No office "Notes for the driver", but the customer's special requests
        // (where ETO comments land) still surface a note to read + confirm.
        $driver = User::factory()->driver()->create();
        $booking = Booking::factory()
            ->forVehicleType(VehicleType::where('slug', 'executive')->first())
            ->create([
                'customer_id' => Customer::factory()->create()->id,
                'driver_id' => $driver->id, 'pickup_at' => now()->addDay(),
                'status' => BookingStatus::Allocated->value,
                'special_requests' => 'Please call the passenger on arrival',
            ]);

        $this->assertSame('Please call the passenger on arrival', $booking->driverReadNotes());
        $this->get(route('driver.link', $booking->driverLinkToken()))->assertOk()
            ->assertSee('Please call the passenger on arrival')
            ->assertSee('I’ve read these notes', false);
    }

    public function test_notes_come_from_the_calendar_with_the_booker_stripped(): void
    {
        // No office note, no special requests — the note lives on the calendar
        // event, prefixed with "Booked by X" which the driver shouldn't see.
        $driver = User::factory()->driver()->create();
        $booking = Booking::factory()
            ->forVehicleType(VehicleType::where('slug', 'executive')->first())
            ->create([
                'customer_id' => Customer::factory()->create()->id,
                'driver_id' => $driver->id, 'pickup_at' => now()->addDay(),
                'status' => BookingStatus::Allocated->value,
            ]);
        $booking->calendarEvents()->create([
            'calendar_id' => 'cal', 'title' => 'x', 'location' => 'x',
            'description' => "• Vehicle Type: Executive\n• *Notes:* Booked by Jo Bloggs. Please call the passenger on arrival",
            'start_at' => now(), 'end_at' => now()->addHour(), 'timezone' => 'Europe/London',
        ]);
        $booking = $booking->fresh();

        // The booker is stripped; only the instruction remains.
        $this->assertSame('Please call the passenger on arrival', $booking->driverReadNotes());
        $this->get(route('driver.link', $booking->driverLinkToken()))->assertOk()
            ->assertSee('Please call the passenger on arrival')
            ->assertDontSee('Booked by Jo Bloggs')
            ->assertSee('I’ve read these notes', false);
    }

    public function test_a_calendar_note_that_is_only_the_booker_shows_nothing(): void
    {
        $booking = Booking::factory()
            ->forVehicleType(VehicleType::where('slug', 'executive')->first())
            ->create(['customer_id' => Customer::factory()->create()->id, 'pickup_at' => now()->addDay()]);
        $booking->calendarEvents()->create([
            'calendar_id' => 'cal', 'title' => 'x', 'location' => 'x',
            'description' => "• Vehicle Type: Executive\n• *Notes:* Booked by Jo Bloggs.",
            'start_at' => now(), 'end_at' => now()->addHour(), 'timezone' => 'Europe/London',
        ]);

        $this->assertNull($booking->fresh()->driverReadNotes());
    }

    public function test_an_extra_car_confirms_notes_per_car(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->notedJob();
        $this->actingAs($admin)->post(route('bookings.extra-drivers.add', $booking), ['name' => 'Sam']);
        $token = $booking->fresh()->extraDrivers()[0]['token'];

        $this->get(route('driver.car', $token))->assertOk()->assertSee('Ring the buzzer for flat 3');
        $this->post(route('driver.car.notes-ack', $token))->assertRedirect();

        $this->assertTrue($booking->fresh()->extraDriverNotesAcknowledged($token));
    }
}
