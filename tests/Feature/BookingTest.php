<?php

namespace Tests\Feature;

use App\Models\Airport;
use App\Models\Booking;
use App\Models\CorporateAccount;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\AirportSeeder;
use Database\Seeders\DirectorSeeder;
use Database\Seeders\RotationSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([VehicleTypeSeeder::class, DirectorSeeder::class, AirportSeeder::class, RotationSeeder::class]);
    }

    private function validPayload(array $overrides = []): array
    {
        $executive = VehicleType::where('slug', 'executive')->first();

        return array_merge([
            'customer_name' => 'James Watson',
            'customer_phone' => '07700900123',
            'vehicle_type_id' => $executive->id,
            'journey_type' => 'one_way',
            'pickup_at' => now()->addDays(2)->format('Y-m-d\TH:i'),
            'pickup_address' => '12 Fargate, Sheffield',
            'destination_address' => 'Manchester Airport',
            'passengers' => 2,
            'luggage' => 2,
            'payment_method' => 'card',
            'privacy_consent' => '1',
        ], $overrides);
    }

    public function test_admin_can_create_a_booking_with_full_side_effects(): void
    {
        $admin = User::factory()->admin()->create();
        $lhr = Airport::where('code', 'LHR')->first();

        $response = $this->actingAs($admin)->post(route('bookings.store'), $this->validPayload([
            'airport_id' => $lhr->id,
            'flight_number' => 'BA1234',
        ]));

        $booking = Booking::first();
        $this->assertNotNull($booking);
        $response->assertRedirect(route('bookings.show', $booking));

        // Customer remembered.
        $this->assertDatabaseHas('customers', ['name' => 'James Watson', 'phone' => '07700900123']);
        // GDPR consent captured.
        $this->assertDatabaseHas('consents', ['type' => 'privacy_notice', 'granted' => true]);
        // Calendar event scaffolded with +1h end and the card emoji.
        $this->assertNotNull($booking->calendarEvent);
        $this->assertEquals('👀', $booking->calendarEvent->payment_emoji);
        $this->assertEquals(
            $booking->pickup_at->copy()->addHour()->format('H:i'),
            $booking->calendarEvent->end_at->format('H:i')
        );
        // Executive job at LHR → ABDI allocated.
        $this->assertEquals('Abdirazak Hassan', $booking->driver->name);
    }

    public function test_return_journey_creates_two_linked_legs(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('bookings.store'), $this->validPayload([
            'journey_type' => 'return',
            'return_pickup_at' => now()->addDays(3)->format('Y-m-d\TH:i'),
        ]));

        $this->assertEquals(2, Booking::count());
        $outbound = Booking::where('is_return_leg', false)->first();
        $return = Booking::where('is_return_leg', true)->first();

        $this->assertEquals($return->id, $outbound->linked_booking_id);
        $this->assertEquals($outbound->id, $return->linked_booking_id);
        // Return leg pickup = outbound destination.
        $this->assertEquals('Manchester Airport', $return->pickup_address);
    }

    public function test_booking_requires_privacy_consent(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('bookings.store'), $this->validPayload(['privacy_consent' => null]))
            ->assertSessionHasErrors('privacy_consent');

        $this->assertEquals(0, Booking::count());
    }

    public function test_passenger_count_cannot_exceed_vehicle_capacity(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('bookings.store'), $this->validPayload(['passengers' => 6])) // Executive seats 4
            ->assertSessionHasErrors('passengers');
    }

    public function test_cost_code_is_mandatory_for_corporate_accounts(): void
    {
        $admin = User::factory()->admin()->create();
        $account = CorporateAccount::create([
            'name' => 'JELD-WEN', 'slug' => 'jeld-wen', 'account_code' => 'JW1',
            'cost_code_required' => true, 'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('bookings.store'), $this->validPayload([
                'corporate_account_id' => $account->id,
                'cost_code' => null,
            ]))
            ->assertSessionHasErrors('cost_code');
    }

    public function test_pickup_must_be_in_the_future(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('bookings.store'), $this->validPayload([
                'pickup_at' => now()->subHour()->format('Y-m-d\TH:i'),
            ]))
            ->assertSessionHasErrors('pickup_at');
    }

    public function test_corporate_client_cannot_view_another_accounts_booking(): void
    {
        $account = CorporateAccount::create([
            'name' => 'LB Foster', 'slug' => 'lb-foster', 'account_code' => 'LB1',
            'cost_code_required' => false, 'is_active' => true,
        ]);
        $otherBooking = Booking::factory()->forVehicleType(VehicleType::where('slug', 'executive')->first())->create();

        $client = User::factory()->corporateClient()->create();
        // Client belongs to a DIFFERENT account.
        $client->corporateAccounts()->attach($account->id);

        $this->actingAs($client)->get(route('bookings.show', $otherBooking))->assertForbidden();
    }

    public function test_admin_can_amend_a_booking(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('bookings.store'), $this->validPayload());
        $booking = Booking::first();
        $vClass = VehicleType::where('slug', 'v-class')->first() ?? VehicleType::where('slug', 'executive')->first();

        $this->actingAs($admin)->put(route('bookings.update', $booking), [
            'customer_name' => 'James Watson',
            'customer_phone' => '07700900999',
            'vehicle_type_id' => $vClass->id,
            'pickup_at' => now()->addDays(4)->format('Y-m-d\TH:i'),
            'pickup_address' => '1 New Street, Sheffield',
            'destination_address' => 'Leeds Bradford Airport',
            'via_stops' => ['Meadowhall Centre'],
            'passengers' => 3,
            'luggage' => 4,
            'payment_method' => 'cash',
            'quoted_price' => 145.50,
        ])->assertRedirect(route('bookings.show', $booking));

        $booking->refresh();
        $this->assertEquals('1 New Street, Sheffield', $booking->pickup_address);
        $this->assertEquals('Leeds Bradford Airport', $booking->destination_address);
        $this->assertEquals(3, $booking->passengers);
        $this->assertEquals('145.50', (string) $booking->quoted_price);
        // Contact detail flowed to the customer record.
        $this->assertEquals('07700900999', $booking->customer->phone);
        // Via stop persisted.
        $this->assertEquals('Meadowhall Centre', $booking->stops()->first()->address);
        // Calendar event kept (updated in place, not duplicated).
        $this->assertEquals(1, $booking->calendarEvent()->count());
    }

    public function test_admin_can_cancel_a_booking_with_a_reason(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('bookings.store'), $this->validPayload());
        $booking = Booking::first();

        $this->actingAs($admin)->post(route('bookings.cancel', $booking), [
            'cancellation_reason' => 'Customer no longer needs the car',
        ])->assertRedirect(route('bookings.show', $booking));

        $booking->refresh();
        $this->assertEquals(\App\Enums\BookingStatus::Cancelled, $booking->status);
        $this->assertEquals('Customer no longer needs the car', $booking->meta['cancellation_reason']);
        // Transition recorded in the audit history.
        $this->assertDatabaseHas('booking_status_histories', [
            'booking_id' => $booking->id,
            'to_status' => 'cancelled',
        ]);
    }

    public function test_cancel_requires_a_reason(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('bookings.store'), $this->validPayload());
        $booking = Booking::first();

        $this->actingAs($admin)->post(route('bookings.cancel', $booking), [])
            ->assertSessionHasErrors('cancellation_reason');

        $this->assertNotEquals(\App\Enums\BookingStatus::Cancelled, $booking->fresh()->status);
    }

    public function test_driver_cannot_edit_or_cancel_bookings(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('bookings.store'), $this->validPayload());
        $booking = Booking::first();

        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->get(route('bookings.edit', $booking))->assertForbidden();
        $this->actingAs($driver)->post(route('bookings.cancel', $booking), [
            'cancellation_reason' => 'nope',
        ])->assertForbidden();
    }
}
