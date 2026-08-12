<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Notes for the driver": free-form info the office adds when editing a booking,
 * stored in meta (so imports/sync never wipe it), shown to the driver.
 */
class DriverNotesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_editing_a_booking_saves_driver_notes(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create(['pickup_at' => now()->addDay()]);

        $payload = array_merge($this->baseForm($booking), [
            'driver_notes' => "Call on arrival — customer at the side entrance.\nMay need help with luggage.",
        ]);

        $this->actingAs($admin)->put(route('bookings.update', $booking), $payload)->assertRedirect();

        $booking = $booking->fresh();
        $this->assertSame(
            "Call on arrival — customer at the side entrance.\nMay need help with luggage.",
            $booking->driverNotes()
        );
    }

    public function test_the_driver_sees_the_notes_on_their_job_screen(): void
    {
        $booking = Booking::factory()->create(['status' => BookingStatus::Accepted]);
        $booking->forceFill(['meta' => ['driver_notes' => 'Customer will meet you in the lobby.']])->save();

        $this->get(route('driver.link', $booking->driverLinkToken()))
            ->assertOk()
            ->assertSee('📝 Notes')
            ->assertSee('Customer will meet you in the lobby.');
    }

    public function test_driver_notes_survive_an_eto_reimport(): void
    {
        // Stored in meta, not a column ETO touches, so a re-import can't wipe it.
        $booking = Booking::factory()->create();
        $booking->forceFill(['meta' => ['driver_notes' => 'VIP — keep it discreet.', 'suitcases' => 2]])->save();

        // Simulate an import updating financials/meta without driver_notes.
        $meta = $booking->fresh()->meta;
        $meta['payment_text'] = 'Deposit £10 Paid – £90 Cash Due';
        $booking->forceFill(['meta' => $meta])->save();

        $this->assertSame('VIP — keep it discreet.', $booking->fresh()->driverNotes());
    }

    public function test_notes_are_null_when_blank(): void
    {
        $booking = Booking::factory()->create();
        $booking->forceFill(['meta' => ['driver_notes' => '   ']])->save();

        $this->assertNull($booking->fresh()->driverNotes());
    }

    private function baseForm(Booking $booking): array
    {
        $vt = VehicleType::first();

        return [
            'customer_name' => $booking->customer->name ?? 'Test Customer',
            'customer_phone' => $booking->customer->phone ?? '07700900000',
            'vehicle_type_id' => $vt->id,
            'pickup_at' => $booking->pickup_at->format('Y-m-d\TH:i'),
            'pickup_address' => $booking->pickup_address ?: 'Sheffield',
            'destination_address' => $booking->destination_address ?: 'Manchester Airport',
            'passengers' => $booking->passengers ?: 1,
            'payment_method' => 'cash',
            'journey_type' => 'one_way',
        ];
    }
}
