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

    public function test_fix_contact_re_files_under_a_new_customer_when_the_record_is_shared(): void
    {
        // The "why does it say Neil?" case: this booking (lead passenger Huzayfa)
        // is filed under an existing customer (Neil) who has OTHER bookings. The
        // fix must NOT overwrite Neil's number — it must re-file this booking under
        // its own customer and leave Neil's record intact.
        $admin = User::factory()->admin()->create();
        $booking = $this->bookingWithCalendarContact('+447535823380', '+447379921855');
        $booking->forceFill(['meta' => array_merge($booking->meta ?? [], ['lead_name' => 'Huzayfa'])])->save();
        $neil = $booking->customer;
        $neil->update(['name' => 'Neil Simmonds']);
        // Neil owns another, unrelated booking.
        $other = Booking::factory()->create(['customer_id' => $neil->id]);

        $this->actingAs($admin)->post(route('bookings.fix-contact', $booking))->assertRedirect();

        // Neil's record is untouched — same number, still on his other booking.
        $this->assertSame('+447535823380', $neil->fresh()->phone);
        $this->assertSame($neil->id, $other->fresh()->customer_id);
        // This booking moved to a NEW customer named from the booking, with the
        // booking's own contact number.
        $moved = $booking->fresh('customer');
        $this->assertNotSame($neil->id, $moved->customer_id);
        $this->assertSame('Huzayfa', $moved->customer->name);
        $this->assertSame('+447379921855', $moved->customer->phone);
    }

    public function test_editing_a_shared_customer_booking_re_links_instead_of_corrupting(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create();
        $neil = $booking->customer;
        $neil->update(['name' => 'Neil Simmonds', 'phone' => '+447535823380']);
        Booking::factory()->create(['customer_id' => $neil->id]); // Neil has another job
        $vt = \App\Models\VehicleType::query()->firstOrFail();

        $this->actingAs($admin)->put(route('bookings.update', $booking), [
            'customer_name' => 'Huzayfa',
            'customer_phone' => '07379921855',
            'vehicle_type_id' => $vt->id,
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'pickup_address' => '1 Test St, Sheffield',
            'destination_address' => 'Manchester Airport',
            'passengers' => 2,
            'luggage' => 1,
            'payment_method' => 'card',
        ])->assertRedirect();

        // Neil is untouched; this booking is now under a different customer.
        $neil->refresh();
        $this->assertSame('Neil Simmonds', $neil->name);
        $this->assertSame('+447535823380', $neil->phone);
        $this->assertNotSame($neil->id, $booking->fresh()->customer_id);
        $this->assertSame('Huzayfa', $booking->fresh('customer')->customer->name);
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
