<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CalendarEvent;
use App\Models\Customer;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InspectPairTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_it_shows_both_legs_and_flags_a_cross_linked_event(): void
    {
        $exec = VehicleType::where('slug', 'executive')->first();
        $cust = Customer::create(['name' => 'Janine Neill', 'phone' => '07985454218']);

        $a = Booking::create([
            'reference' => Booking::generateReference(), 'external_reference' => '9Y5MDRa',
            'customer_id' => $cust->id, 'vehicle_type_id' => $exec->id, 'source_system' => 'eto',
            'pickup_at' => '2026-10-04 08:55', 'pickup_address' => 'Manchester Airport (MAN)',
            'destination_address' => '2 Worrygoose Lane, Rotherham', 'passengers' => 1,
            'status' => 'pending', 'payment_method' => 'card',
        ]);
        $b = Booking::create([
            'reference' => Booking::generateReference(), 'external_reference' => '9Y5MDRb',
            'customer_id' => $cust->id, 'vehicle_type_id' => $exec->id, 'source_system' => 'eto',
            'pickup_at' => '2026-10-08 10:00', 'pickup_address' => '2 Worrygoose Lane, Rotherham',
            'destination_address' => 'Manchester Airport (MAN)', 'passengers' => 1,
            'status' => 'pending', 'payment_method' => 'card',
        ]);
        // Leg B is cross-linked to an event whose text carries leg A's reference.
        CalendarEvent::create([
            'booking_id' => $b->id, 'title' => '*Janine Neill MAN (ABDI)*',
            'description' => 'Booking Reference: 9Y5MDRa', 'start_at' => '2026-10-04 08:55',
            'end_at' => '2026-10-04 09:55', 'sync_status' => 'synced',
        ]);

        $code = \Illuminate\Support\Facades\Artisan::call('cet:inspect-pair', ['reference' => '9Y5MDR']);
        $out = \Illuminate\Support\Facades\Artisan::output();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('9Y5MDRa', $out);         // both legs listed
        $this->assertStringContainsString('9Y5MDRb', $out);
        $this->assertStringContainsString('CROSS-LINKED', $out);    // the cross-link is flagged
    }
}
