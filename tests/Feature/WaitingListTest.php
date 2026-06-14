<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use App\Models\WaitingListEntry;
use App\Services\BookingStatusService;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaitingListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function bookingOn(string $date): Booking
    {
        return Booking::factory()->forVehicleType(VehicleType::where('slug', 'executive')->first())->create([
            'status' => BookingStatus::Pending->value,
            'pickup_at' => $date.' 10:00',
        ]);
    }

    private function waiter(string $date, ?int $vehicleTypeId = null): WaitingListEntry
    {
        $customer = Customer::factory()->create(['phone' => '07700900321']);

        return WaitingListEntry::create([
            'customer_id' => $customer->id,
            'vehicle_type_id' => $vehicleTypeId,
            'desired_date' => $date,
            'status' => 'waiting',
        ]);
    }

    public function test_cancellation_notifies_a_matching_waiter(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->bookingOn('2026-07-01');
        $entry = $this->waiter('2026-07-01');

        app(BookingStatusService::class)->transition($booking, BookingStatus::Cancelled, $admin);

        $entry->refresh();
        $this->assertEquals('notified', $entry->status);
        $this->assertNotNull($entry->notified_at);
        $this->assertDatabaseHas('messages', [
            'type' => 'waiting_list', 'to_address' => '07700900321',
        ]);
    }

    public function test_waiter_on_a_different_date_is_not_notified(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->bookingOn('2026-07-01');
        $entry = $this->waiter('2026-07-05');

        app(BookingStatusService::class)->transition($booking, BookingStatus::Cancelled, $admin);

        $this->assertEquals('waiting', $entry->refresh()->status);
    }

    public function test_vehicle_type_specific_waiter_only_matches_same_type(): void
    {
        $admin = User::factory()->admin()->create();
        $minibus = VehicleType::where('slug', 'minibus-8')->first();
        $booking = $this->bookingOn('2026-07-01'); // Executive booking
        $entry = $this->waiter('2026-07-01', $minibus->id); // wants a minibus

        app(BookingStatusService::class)->transition($booking, BookingStatus::Cancelled, $admin);

        $this->assertEquals('waiting', $entry->refresh()->status);
    }

    public function test_admin_can_add_a_waiting_list_entry(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('waiting-list.store'), [
            'customer_name' => 'New Waiter',
            'customer_phone' => '07700900999',
            'desired_date' => now()->addWeek()->toDateString(),
        ])->assertRedirect();

        $this->assertDatabaseHas('waiting_list_entries', ['status' => 'waiting']);
        $this->assertDatabaseHas('customers', ['phone' => '07700900999']);
    }
}
