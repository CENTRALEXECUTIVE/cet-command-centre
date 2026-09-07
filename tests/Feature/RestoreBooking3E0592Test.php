<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\CalendarEvent;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestoreBooking3E0592Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_it_restores_the_lawrence_covering_job_and_leaves_penny_alone(): void
    {
        $exec = VehicleType::where('slug', 'executive')->first();
        $penny = Customer::create(['name' => 'Penny Coates', 'phone' => '07999111222']);

        // Penny's real, completed booking — must be left untouched.
        $pennyReal = Booking::factory()->create([
            'reference' => 'CET-09A10D', 'customer_id' => $penny->id,
            'status' => BookingStatus::Complete->value, 'pickup_at' => '2026-08-30 13:45:00',
        ]);

        // The polluted CET-3E0592: Lawrence's job overwritten with Penny's details.
        $polluted = Booking::factory()->create([
            'reference' => 'CET-3E0592',
            'customer_id' => $penny->id,             // wrongly pointing at Penny
            'vehicle_type_id' => $exec->id,          // was rewritten to Executive
            'external_reference' => null,
            'status' => BookingStatus::Pending->value,
            'pickup_at' => '2026-08-30 13:45:00',    // Penny's time
            'pickup_address' => 'The Old Vicarage, Barlow',
            'destination_address' => 'Somewhere else',
            'passengers' => 2,
        ]);
        $polluted->forceFill(['meta' => ['driver_details' => ['name' => 'Maj'], 'payment_text' => 'Paid £280 (Stripe)']])->save();
        CalendarEvent::create([
            'booking_id' => $polluted->id, 'google_event_id' => 'evt_penny',
            'title' => '*Penny Coates MAN (MAJ)*', 'location' => 'The Old Vicarage, Barlow',
            'description' => 'Booking Reference: CET-09A10D', 'start_at' => '2026-08-30 13:45:00',
            'end_at' => '2026-08-30 14:45:00', 'sync_status' => 'synced',
        ]);

        $this->artisan('cet:restore-3e0592')->assertSuccessful();

        $fresh = $polluted->fresh(['customer', 'calendarEvent']);
        $this->assertSame('Ryanhn', $fresh->external_reference);
        $this->assertStringContainsString('Lawrence', $fresh->customer->name);
        $this->assertSame('2026-09-23 15:05', $fresh->pickup_at->format('Y-m-d H:i'));
        $this->assertSame('Manchester Airport', $fresh->pickup_address);
        $this->assertSame('19 Horsewood Road S13 9WL', $fresh->destination_address);
        $this->assertNull($fresh->driver_id);
        $this->assertSame(VehicleType::where('slug', 'estate')->first()->id, $fresh->vehicle_type_id);
        // The poisoned calendar link is gone (a fresh event was built, not evt_penny).
        $this->assertNotSame('evt_penny', $fresh->calendarEvent?->google_event_id);

        // Penny's real booking is untouched, and her customer record is NOT renamed.
        $this->assertSame('CET-09A10D', $pennyReal->fresh()->reference);
        $this->assertSame('2026-08-30 13:45', $pennyReal->fresh()->pickup_at->format('Y-m-d H:i'));
        $this->assertSame('Penny Coates', $penny->fresh()->name);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $exec = VehicleType::where('slug', 'executive')->first();
        $booking = Booking::factory()->create([
            'reference' => 'CET-3E0592', 'vehicle_type_id' => $exec->id,
            'pickup_at' => '2026-08-30 13:45:00',
        ]);

        $this->artisan('cet:restore-3e0592 --dry')->assertSuccessful();

        $this->assertNull($booking->fresh()->external_reference);
        $this->assertSame('2026-08-30 13:45', $booking->fresh()->pickup_at->format('Y-m-d H:i'));
    }
}
