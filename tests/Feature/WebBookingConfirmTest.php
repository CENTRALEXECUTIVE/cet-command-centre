<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\VehicleType;
use App\Services\BookingStatusService;
use Database\Seeders\AirportSeeder;
use Database\Seeders\DirectorSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * When a public web booking is paid in full online, it is promoted from a
 * pending request to a confirmed job: VAT stamped, a rotation driver allocated
 * (for saloon work), and a calendar event built — without the office lifting a
 * finger. Nothing is pushed to Google here; the sync job does that.
 */
class WebBookingConfirmTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([VehicleTypeSeeder::class, AirportSeeder::class, DirectorSeeder::class]);
        config(['cet.vat_registered' => true, 'cet.vat_rate' => 0.20]);
    }

    private function paidWebBooking(VehicleType $type): Booking
    {
        return Booking::factory()->create([
            'customer_id' => Customer::create(['name' => 'Web Customer', 'email' => 'web@example.com'])->id,
            'vehicle_type_id' => $type->id,
            'source' => 'web',
            'status' => BookingStatus::Pending->value,
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'quoted_price' => 290,
            'is_return_leg' => false,
        ]);
    }

    public function test_a_paid_saloon_web_booking_is_allocated_and_calendared(): void
    {
        $executive = VehicleType::where('affects_rotation', true)->firstOrFail();
        $booking = $this->paidWebBooking($executive);

        app(BookingStatusService::class)->confirmPaidWebBooking($booking);

        $booking->refresh();
        $this->assertSame(BookingStatus::Allocated, $booking->status);
        $this->assertNotNull($booking->driver_id, 'A rotation driver should be allocated.');
        $this->assertNotNull($booking->calendarEvent, 'A calendar event should be built.');
        // VAT is frozen for the receipt (290 gross → 48.33 VAT at 20%).
        $this->assertSame(48.33, $booking->meta['vat']['vat']);
    }

    public function test_it_is_idempotent_and_ignores_unpaid_bookings(): void
    {
        $executive = VehicleType::where('affects_rotation', true)->firstOrFail();

        // Unpaid → untouched.
        $unpaid = $this->paidWebBooking($executive);
        $unpaid->forceFill(['payment_status' => 'pending'])->save();
        app(BookingStatusService::class)->confirmPaidWebBooking($unpaid);
        $this->assertSame(BookingStatus::Pending, $unpaid->fresh()->status);

        // Paid → confirmed once; a second call does not change the driver.
        $paid = $this->paidWebBooking($executive);
        app(BookingStatusService::class)->confirmPaidWebBooking($paid);
        $firstDriver = $paid->fresh()->driver_id;
        app(BookingStatusService::class)->confirmPaidWebBooking($paid->fresh());
        $this->assertSame($firstDriver, $paid->fresh()->driver_id);
    }
}
