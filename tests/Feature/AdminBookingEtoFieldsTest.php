<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\CalendarEventBuilder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ETO-parity fields the admin booking form was missing: the meet & greet name
 * board + meeting point, and how the booking came in (source). They must be
 * captured, stored, and — for the meet & greet detail — reach the driver sheet
 * and the calendar, like every other job detail.
 */
class AdminBookingEtoFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function storeBooking(User $admin, array $overrides = []): void
    {
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();

        $this->actingAs($admin)->post(route('bookings.store'), array_merge([
            'customer_name' => 'Test Customer', 'customer_phone' => '07700900123',
            'journey_type' => 'one_way',
            'pickup_address' => 'Manchester Airport', 'destination_address' => '12 Test Road, Sheffield',
            'pickup_at' => now()->addDays(2)->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $exec->id, 'passengers' => 2,
            'payment_method' => 'card', 'quoted_price' => 100.00, 'privacy_consent' => '1',
        ], $overrides))->assertRedirect();
    }

    public function test_meeting_board_point_and_source_are_captured(): void
    {
        $admin = User::factory()->admin()->create();

        $this->storeBooking($admin, [
            'meet_greet' => '1',
            'meeting_board' => 'Mr J Smith',
            'meeting_point' => 'Costa, arrivals hall',
            'booking_source' => 'whatsapp',
        ]);

        $booking = Booking::latest('id')->first();
        $this->assertSame('Mr J Smith', $booking->meta['meeting_board']);
        $this->assertSame('Costa, arrivals hall', $booking->meta['meeting_point']);
        $this->assertSame('whatsapp', $booking->meta['enquiry_source']);
        $this->assertTrue($booking->isMeetGreet());

        // And they read back for the booking page in a readable form.
        $this->assertSame('WhatsApp', $booking->enquirySourceLabel());
        $this->assertStringContainsString('Mr J Smith', (string) $booking->displayMeetAndGreet());
        $this->assertStringContainsString('Costa, arrivals hall', (string) $booking->displayMeetAndGreet());
    }

    public function test_meet_greet_from_the_admin_form_reaches_the_calendar(): void
    {
        $admin = User::factory()->admin()->create();

        $this->storeBooking($admin, [
            'meet_greet' => '1',
            'meeting_board' => 'Mr J Smith',
            'meeting_point' => 'Costa, arrivals hall',
        ]);

        $booking = Booking::latest('id')->first();
        $description = app(CalendarEventBuilder::class)->preview($booking)['description'] ?? '';

        // The meet & greet line now fires for an admin-form booking (meta['meet_greet'])
        // and carries the board name and meeting point.
        $this->assertStringContainsString('Meet & Greet', $description);
        $this->assertStringContainsString('Mr J Smith', $description);
        $this->assertStringContainsString('Costa, arrivals hall', $description);
    }

    public function test_the_fields_are_optional(): void
    {
        $admin = User::factory()->admin()->create();

        // No meet & greet, no source — must still save cleanly with nothing stored.
        $this->storeBooking($admin);

        $booking = Booking::latest('id')->first();
        $this->assertArrayNotHasKey('meeting_board', $booking->meta ?? []);
        $this->assertArrayNotHasKey('enquiry_source', $booking->meta ?? []);
    }
}
