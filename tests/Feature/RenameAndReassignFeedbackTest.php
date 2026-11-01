<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Matt Shaw, Nov 2026: his number was saved under "Jane Shaw" (his wife once
 * booked on it). Renaming the customer left the VAT invoice billing Jane, and the
 * rotation driver change to Abdi "wouldn't save" with no message.
 */
class RenameAndReassignFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_renaming_a_customer_updates_the_name_on_their_upcoming_booking_but_not_old_ones(): void
    {
        $customer = Customer::create(['name' => 'Jane Shaw', 'phone' => '07989605549']);
        $old = Booking::factory()->create([
            'customer_id' => $customer->id, 'status' => BookingStatus::Complete,
            'meta' => ['lead_name' => 'Jane Shaw'],
        ]);
        $upcoming = Booking::factory()->create([
            'customer_id' => $customer->id, 'status' => BookingStatus::Pending,
            'meta' => ['lead_name' => 'Jane Shaw', 'booked_by' => 'Jane Shaw'],
        ]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put(route('customers.update', $customer), [
            'name' => 'Matthew Shaw', 'phone' => '07989605549',
        ])->assertRedirect();

        $upcoming->refresh();
        $this->assertSame('Matthew Shaw', $upcoming->displayName());
        $this->assertSame('Matthew Shaw', $upcoming->bookerName()); // invoice "Bill to"
        $this->assertSame('Jane Shaw', $old->fresh()->displayName()); // history kept
    }

    public function test_editing_the_name_on_a_booking_also_fixes_the_stored_booker(): void
    {
        $vt = VehicleType::where('slug', 'executive')->first();
        $customer = Customer::create(['name' => 'Matthew Shaw', 'phone' => '07989605549']);
        $b = Booking::factory()->create([
            'customer_id' => $customer->id, 'vehicle_type_id' => $vt->id,
            'status' => BookingStatus::Pending, 'pickup_at' => now()->addDays(3),
            'meta' => ['lead_name' => 'Jane Shaw', 'booked_by' => 'Jane Shaw'],
        ]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put(route('bookings.update', $b), [
            'customer_name' => 'Matthew Shaw',
            'customer_phone' => '07989605549',
            'vehicle_type_id' => $vt->id,
            'pickup_at' => $b->pickup_at->format('Y-m-d\TH:i'),
            'pickup_address' => '33 Ranmoor Crescent, Sheffield S10 3GW',
            'destination_address' => 'Manchester Airport T2',
            'passengers' => 1,
            'payment_method' => 'card',
        ])->assertRedirect();

        $b->refresh();
        $this->assertSame('Matthew Shaw', $b->displayName());
        $this->assertSame('Matthew Shaw', $b->bookerName());
    }

    public function test_a_blocked_driver_change_says_why_on_the_booking_page(): void
    {
        $admin = User::factory()->admin()->create();
        $majid = User::factory()->driver()->create(['name' => 'Majid Ali']);
        DriverProfile::create(['user_id' => $majid->id, 'is_third_party' => true]);
        $abdi = User::factory()->driver()->create(['name' => 'Abdi Hassan']);
        DriverProfile::create(['user_id' => $abdi->id, 'is_third_party' => true, 'phv_badge_expiry' => now()->subDays(3)]);
        $b = Booking::factory()->create(['driver_id' => $majid->id, 'status' => BookingStatus::Pending]);

        $this->actingAs($admin)->from(route('bookings.show', $b))
            ->post(route('despatch.reassign', $b), ['driver_id' => $abdi->id])
            ->assertRedirect(route('bookings.show', $b));

        $this->assertSame($majid->id, $b->fresh()->driver_id);
        $this->actingAs($admin)->get(route('bookings.show', $b))
            ->assertSee('Cannot reassign to Abdi Hassan')
            ->assertSee('PHV Badge expired');
    }

    public function test_a_pending_job_with_a_driver_can_be_moved_to_another_driver(): void
    {
        $admin = User::factory()->admin()->create();
        $majid = User::factory()->driver()->create();
        DriverProfile::create(['user_id' => $majid->id, 'is_third_party' => true]);
        $abdi = User::factory()->driver()->create();
        DriverProfile::create(['user_id' => $abdi->id, 'is_third_party' => true]);
        $b = Booking::factory()->create(['driver_id' => $majid->id, 'status' => BookingStatus::Pending]);

        $this->actingAs($admin)->post(route('despatch.reassign', $b), ['driver_id' => $abdi->id])->assertRedirect();

        $this->assertSame($abdi->id, $b->fresh()->driver_id);
    }

    public function test_name_on_invoice_overrides_the_bill_to_name(): void
    {
        $customer = Customer::create(['name' => 'Jane Shaw', 'phone' => '07989605549']);
        $b = Booking::factory()->create(['customer_id' => $customer->id, 'status' => BookingStatus::Pending]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('bookings.invoice-name', $b), ['invoice_name' => 'Matthew Shaw'])
            ->assertRedirect()->assertSessionHas('status');

        $billedTo = null;
        \Illuminate\Support\Facades\View::composer('pdf.receipt', function ($view) use (&$billedTo) {
            $billedTo = $view->getData()['billedTo'] ?? null;
        });
        app(\App\Services\Payments\InvoicePdf::class)->renderReceipt($b->fresh());
        $this->assertSame('Matthew Shaw', $billedTo);

        // Blank clears it — back to the customer on the booking.
        $this->actingAs($admin)->post(route('bookings.invoice-name', $b), ['invoice_name' => '']);
        $this->assertArrayNotHasKey('invoice_name', $b->fresh()->meta ?? []);
    }
}
