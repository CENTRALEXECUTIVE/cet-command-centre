<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Create return leg" from an existing one-way booking — a new booking with the
 * ends swapped, linked both ways, left Pending and unpriced for the office.
 */
class BookingReturnLegTest extends TestCase
{
    use RefreshDatabase;

    private function oneWay(): Booking
    {
        return Booking::factory()->create([
            'journey_type' => 'one_way',
            'status' => BookingStatus::Pending,
            'pickup_address' => '12 Test Road, Sheffield',
            'destination_address' => 'Manchester Airport',
            'pickup_at' => now()->addDays(2)->setTime(9, 0),
            'quoted_price' => 100.00,
            'passengers' => 3,
        ]);
    }

    public function test_it_creates_a_linked_return_with_the_ends_swapped(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->oneWay();

        $when = now()->addDays(5)->setTime(17, 15);
        $this->actingAs($admin)->post(route('bookings.return-leg', $booking), [
            'return_pickup_at' => $when->format('Y-m-d\TH:i'),
            'flight_number' => 'BA1235',
        ])->assertRedirect();

        $return = Booking::where('id', '!=', $booking->id)->latest('id')->first();

        // Ends swapped.
        $this->assertSame('Manchester Airport', $return->pickup_address);
        $this->assertSame('12 Test Road, Sheffield', $return->destination_address);
        // Same customer, vehicle, passengers.
        $this->assertSame($booking->customer_id, $return->customer_id);
        $this->assertSame($booking->vehicle_type_id, $return->vehicle_type_id);
        $this->assertSame(3, $return->passengers);
        // Pending, unpriced, unallocated, marked a return leg.
        $this->assertSame(BookingStatus::Pending, $return->status);
        $this->assertNull($return->quoted_price);
        $this->assertNull($return->driver_id);
        $this->assertTrue((bool) $return->is_return_leg);
        $this->assertSame('17:15', $return->pickup_at->format('H:i')); // UK-local, not shifted
        $this->assertSame('BA1235', $return->flight_number);
        // Linked both ways.
        $this->assertSame($return->id, $booking->fresh()->linked_booking_id);
        $this->assertSame($booking->id, $return->linked_booking_id);
    }

    public function test_it_will_not_create_a_second_return_for_a_paired_booking(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->oneWay();

        $this->actingAs($admin)->post(route('bookings.return-leg', $booking), [
            'return_pickup_at' => now()->addDays(5)->format('Y-m-d\TH:i'),
        ])->assertRedirect();

        $countAfterFirst = Booking::count();

        // Second attempt on the now-paired booking makes nothing.
        $this->actingAs($admin)->post(route('bookings.return-leg', $booking->fresh()), [
            'return_pickup_at' => now()->addDays(6)->format('Y-m-d\TH:i'),
        ])->assertRedirect();

        $this->assertSame($countAfterFirst, Booking::count());
    }

    public function test_it_is_admin_only(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $booking = $this->oneWay();

        $this->actingAs($driver)->post(route('bookings.return-leg', $booking), [
            'return_pickup_at' => now()->addDays(5)->format('Y-m-d\TH:i'),
        ])->assertForbidden();
    }
}
