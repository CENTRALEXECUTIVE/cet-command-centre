<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CalendarEvent;
use App\Models\Customer;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CorrectPickupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_it_corrects_a_completed_bookings_pickup_on_our_records(): void
    {
        $exec = VehicleType::where('slug', 'executive')->first();
        // The real 9Y5MDRa: an ARRIVAL that wrongly took its return's 08 Oct slot.
        $booking = Booking::create([
            'reference' => Booking::generateReference(), 'external_reference' => '9Y5MDRa',
            'customer_id' => Customer::create(['name' => 'Janine Neill'])->id,
            'vehicle_type_id' => $exec->id, 'source_system' => 'eto',
            'pickup_at' => '2026-10-08 09:00', // WRONG — the return's slot
            'pickup_address' => 'Terminal 2, Manchester', 'destination_address' => '2 Worrygoose Lane',
            'passengers' => 1, 'status' => 'complete', 'payment_method' => 'card', 'payment_status' => 'paid',
        ]);
        $event = CalendarEvent::create([
            'booking_id' => $booking->id, 'title' => '*Janine Neill MAN (ABDI)*',
            'description' => 'Booking Reference: 9Y5MDRa', 'start_at' => '2026-10-08 09:00',
            'end_at' => '2026-10-08 10:00', 'sync_status' => 'synced',
        ]);

        $code = Artisan::call('cet:correct-pickup', [
            'reference' => '9Y5MDRa', 'datetime' => '2026-10-04 09:15', '--force' => true,
        ]);

        $this->assertSame(0, $code);
        $fresh = $booking->fresh();
        $this->assertSame('2026-10-04 09:15', $fresh->pickup_at->format('Y-m-d H:i'));
        // Marked as an office edit so a later ETO re-ingest won't revert it.
        $this->assertContains('pickup_at', (array) $fresh->meta['edited_fields']);
        // Our local calendar mirror moved too (NOT a Google write).
        $this->assertSame('2026-10-04 09:15', $event->fresh()->start_at->format('Y-m-d H:i'));
    }

    public function test_it_reports_when_the_reference_is_unknown(): void
    {
        $this->assertSame(1, Artisan::call('cet:correct-pickup', [
            'reference' => 'NOPE', 'datetime' => '2026-10-04 09:15', '--force' => true,
        ]));
    }
}
