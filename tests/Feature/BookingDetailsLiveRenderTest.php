<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CalendarEvent;
use App\Models\User;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The booking page's "Full details" block renders LIVE from the booking record,
 * not from a frozen Google Calendar snapshot. So editing a booking updates it,
 * and a stale calendar event (e.g. from a different leg or an old date) can never
 * show the wrong details.
 */
class BookingDetailsLiveRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_full_details_follow_the_booking_not_a_stale_calendar_event(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = \App\Models\Customer::create(['name' => 'Geoff Bowen', 'phone' => '07700900007']);

        $booking = Booking::factory()->create([
            'customer_id' => $customer->id,
            'pickup_at' => Carbon::parse('2026-06-24 11:00'),
            'pickup_address' => '14 Kings Road, Doncaster',
            'destination_address' => 'Manchester Airport (MAN)',
        ]);

        // A stale calendar snapshot from the OLD date with WRONG details.
        CalendarEvent::create([
            'booking_id' => $booking->id,
            'title' => '*Someone Else MAN (ABDI)*',
            'description' => "Booking Confirmation\nDate: 02/06/2026\nPickup: STALECALENDARMARKER",
            'start_at' => Carbon::parse('2026-06-02 11:00'),
            'end_at' => Carbon::parse('2026-06-02 12:00'),
            'timezone' => 'Europe/London',
        ]);

        $html = $this->actingAs($admin)->get(route('bookings.show', $booking))->assertOk()->getContent();

        // The live booking date + address are shown, never the stale calendar snapshot.
        $this->assertStringContainsString('24 Jun', $html);
        $this->assertStringContainsString('14 Kings Road, Doncaster', $html);
        $this->assertStringNotContainsString('STALECALENDARMARKER', $html);
    }
}
