<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * THE OFFICE IS THE BOSS: renaming a booking's customer must take everywhere —
 * including displayName() (the driver link), not just the customer record — even
 * when an imported meta['lead_name'] is present.
 */
class BookingRenameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_renaming_a_booking_updates_the_name_the_driver_sees(): void
    {
        $vt = VehicleType::where('slug', 'v-class')->first();
        $customer = Customer::create(['name' => 'Birmingham Corporate Travel Ltd', 'phone' => '07700900500']);
        $b = Booking::factory()->create([
            'customer_id' => $customer->id,
            'vehicle_type_id' => $vt->id,
            'status' => BookingStatus::Allocated,
            'pickup_at' => now()->addHours(2),
            // The imported lead_name that was shadowing the edited name.
            'meta' => ['lead_name' => 'Birmingham Corporate Travel Ltd'],
        ]);
        $admin = User::factory()->admin()->create();

        $this->assertSame('Birmingham Corporate Travel Ltd', $b->displayName());

        $this->actingAs($admin)->put(route('bookings.update', $b), [
            'customer_name' => 'AMRC',
            'customer_phone' => '07700900500',
            'vehicle_type_id' => $vt->id,
            'pickup_at' => $b->pickup_at->format('Y-m-d\TH:i'),
            'pickup_address' => 'Courtyard Marriott Sheffield, S60 5GR',
            'destination_address' => 'AMRC, Brunel Way, Rotherham, S60 5WG',
            'passengers' => 6,
            'payment_method' => 'card',
        ])->assertRedirect();

        $b->refresh();
        $this->assertSame('AMRC', $b->displayName());               // driver link source
        $this->assertSame('AMRC', $b->meta['lead_name']);            // imported name kept in step
        $this->assertSame('AMRC', $b->customer->fresh()->name);      // record updated too
    }

    public function test_an_unedited_booking_still_uses_the_imported_lead_name(): void
    {
        $customer = Customer::create(['name' => 'Record Name', 'phone' => '07700900600']);
        $b = Booking::factory()->create([
            'customer_id' => $customer->id,
            'meta' => ['lead_name' => 'Imported Lead'],
        ]);

        $this->assertSame('Imported Lead', $b->displayName());
    }
}
