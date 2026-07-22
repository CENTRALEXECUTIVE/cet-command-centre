<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CalendarEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The data-integrity check for bookings whose linked customer record carries a
 * different phone to the booking's calendar "Contact No".
 */
class ContactNumberIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function bookingWithCalendarContact(string $recordPhone, string $calendarContact): Booking
    {
        $booking = Booking::factory()->create();
        $booking->customer->update(['phone' => $recordPhone]);

        CalendarEvent::create([
            'booking_id' => $booking->id,
            'google_event_id' => 'evt_'.$booking->id,
            'title' => '*Test MAN (MAJ)*',
            'location' => 'Manchester Airport',
            'description' => "📑 *Booking Confirmation*\n• *Contact No:* {$calendarContact}",
            'start_at' => $booking->pickup_at,
            'end_at' => $booking->pickup_at->copy()->addHour(),
            'sync_status' => 'synced',
        ]);

        return $booking->fresh(['customer', 'calendarEvent']);
    }

    public function test_mismatch_is_detected_and_returns_the_calendar_number(): void
    {
        $booking = $this->bookingWithCalendarContact('07588804226', '+447971871155');

        $this->assertSame('+447971871155', $booking->contactNumberMismatch());
    }

    public function test_no_mismatch_when_numbers_agree_even_if_formatted_differently(): void
    {
        // Same number, different formatting → not a mismatch.
        $booking = $this->bookingWithCalendarContact('07971871155', '+44 7971 871155');

        $this->assertNull($booking->contactNumberMismatch());
    }

    public function test_no_mismatch_without_a_calendar_contact(): void
    {
        $booking = Booking::factory()->create();
        $booking->customer->update(['phone' => '07588804226']);

        $this->assertNull($booking->fresh('customer')->contactNumberMismatch());
    }

    public function test_admin_can_fix_the_record_to_the_calendar_number(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->bookingWithCalendarContact('07588804226', '+447971871155');

        $this->actingAs($admin)->post(route('bookings.fix-contact', $booking))->assertRedirect();

        $this->assertSame('+447971871155', $booking->customer->fresh()->phone);
        $this->assertNull($booking->fresh(['customer', 'calendarEvent'])->contactNumberMismatch());
    }

    public function test_the_command_reports_and_can_fix_mismatches(): void
    {
        $this->bookingWithCalendarContact('07588804226', '+447971871155');

        $this->artisan('cet:check-contact-numbers')
            ->expectsOutputToContain('+447971871155')
            ->assertSuccessful();

        $this->artisan('cet:check-contact-numbers --fix')->assertSuccessful();

        $this->assertDatabaseHas('customers', ['phone' => '+447971871155']);
        $this->assertDatabaseMissing('customers', ['phone' => '07588804226']);
    }
}
