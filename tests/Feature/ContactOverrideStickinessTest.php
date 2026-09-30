<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CalendarEvent;
use App\Models\Customer;
use App\Models\VehicleType;
use App\Services\BookingService;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "I'm the boss — listen to what I input." When the office edits a booking's
 * contact number, that number must WIN and stick — it must not silently revert to
 * the ETO number mirrored from the Google Calendar's "Contact No" line.
 */
class ContactOverrideStickinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function bookingWithCalendarContact(string $etoNumber): Booking
    {
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();
        $customer = Customer::factory()->create(['name' => 'Alex', 'phone' => $etoNumber]);
        $booking = Booking::factory()->forVehicleType($exec)->create([
            'customer_id' => $customer->id,
            'pickup_at' => now()->addDays(2)->setTime(12, 0),
            'pickup_address' => 'Sheffield S1 2HH',
            'destination_address' => 'Manchester Airport',
        ]);
        CalendarEvent::create([
            'booking_id' => $booking->id,
            'calendar_id' => 'admin@centralexecutivetransfers.co.uk',
            'google_event_id' => 'evt_contact',
            'title' => '*Alex MAN (COVER)*',
            'location' => 'Sheffield S1 2HH',
            'description' => "📑 *Booking Confirmation*\n• *Contact No:* {$etoNumber}\n• *Pickup Location:* Sheffield S1 2HH",
            'start_at' => $booking->pickup_at,
            'end_at' => $booking->pickup_at->copy()->addHour(),
            'sync_status' => 'synced',
        ]);

        return $booking->fresh(['calendarEvent', 'customer']);
    }

    private function update(Booking $booking, string $phone): void
    {
        app(BookingService::class)->updateFromForm($booking->fresh(['calendarEvent', 'customer']), [
            'customer_name' => 'Alex',
            'customer_phone' => $phone,
            'vehicle_type_id' => $booking->vehicle_type_id,
            'pickup_at' => $booking->pickup_at->format('Y-m-d\TH:i'),
            'pickup_address' => 'Sheffield S1 2HH',
            'destination_address' => 'Manchester Airport',
            'passengers' => 2,
            'payment_method' => 'cash',
        ]);
    }

    public function test_an_edited_contact_number_wins_over_the_calendar(): void
    {
        // Calendar (ETO) has one number; the office types a different one.
        $booking = $this->bookingWithCalendarContact('07000000000');

        $this->update($booking, '07464905385');

        $this->assertSame('07464905385', $booking->fresh(['calendarEvent', 'customer'])->displayContact());
    }

    public function test_leaving_the_contact_unchanged_keeps_mirroring_the_calendar(): void
    {
        // The edit form pre-fills the shown (calendar) number; submitting it
        // unchanged must NOT pin an override — it keeps mirroring the calendar.
        $booking = $this->bookingWithCalendarContact('07000000000');

        $this->update($booking, '07000000000');

        $fresh = $booking->fresh(['calendarEvent', 'customer']);
        $this->assertSame('07000000000', $fresh->displayContact());
        // No override value was pinned — it keeps mirroring the calendar.
        $this->assertEmpty($fresh->meta['contact_override'] ?? null);
    }

    public function test_a_pinned_contact_survives_a_later_unrelated_edit(): void
    {
        $booking = $this->bookingWithCalendarContact('07000000000');
        $this->update($booking, '07464905385'); // pin the office number

        // A later edit that leaves the phone as the (now-overridden) number must
        // not drop the override back to the ETO calendar value.
        $this->update($booking->fresh(['calendarEvent', 'customer']), '07464905385');

        $this->assertSame('07464905385', $booking->fresh(['calendarEvent', 'customer'])->displayContact());
    }
}
