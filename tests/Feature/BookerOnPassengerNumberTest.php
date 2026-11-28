<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Laura books for Matt on Matt's mobile: the booker name/email must stick. */
class BookerOnPassengerNumberTest extends TestCase
{
    use RefreshDatabase;

    private function data(array $o = []): array
    {
        $vt = VehicleType::first() ?? VehicleType::create(['name' => 'Executive', 'slug' => 'executive', 'passenger_capacity' => 4, 'affects_rotation' => true]);

        return array_merge([
            'customer_name' => 'Laura Ann Lawhern', 'lead_name' => 'Matt Cannell',
            'customer_phone' => '+447949726109', 'customer_email' => 'lauraann@jointmedica.com',
            'pickup_address' => '1 Totley Mews, Sheffield S17 4BG', 'destination_address' => 'Manchester Airport M90 1QX',
            'pickup_at' => now()->addDays(5)->format('Y-m-d H:i'), 'vehicle_type_id' => $vt->id,
            'passengers' => 1, 'journey_type' => 'one_way', 'payment_method' => 'card', 'quoted_price' => 105,
        ], $o);
    }

    public function test_new_booking_keeps_the_typed_booker_not_the_number_owner(): void
    {
        $matt = Customer::create(['name' => 'Matt Cannell', 'phone' => '+447949726109', 'email' => 'cannellmatt@aol.com']);
        $b = app(BookingService::class)->createFromForm($this->data(), User::factory()->create(['role' => UserRole::Admin]));

        $this->assertNotSame($matt->id, $b->customer_id);
        $this->assertSame('Laura Ann Lawhern', $b->customer->name);
        $this->assertSame('lauraann@jointmedica.com', $b->customer->email);
        $this->assertSame('Matt Cannell', $matt->fresh()->name, 'Matt\'s own record is untouched');
    }

    public function test_same_person_still_matches_by_number(): void
    {
        $matt = Customer::create(['name' => 'Matt Cannell', 'phone' => '+447949726109', 'email' => 'cannellmatt@aol.com']);
        $b = app(BookingService::class)->createFromForm($this->data(['customer_name' => 'Matt Cannell', 'lead_name' => null, 'customer_email' => 'cannellmatt@aol.com']), null);
        $this->assertSame($matt->id, $b->customer_id);

        $c = app(BookingService::class)->createFromForm($this->data(['customer_name' => 'Matthew', 'customer_email' => null, 'lead_name' => null]), null);
        $this->assertSame($matt->id, $c->customer_id, 'no email typed: the number owner as before');
    }

    public function test_editing_to_the_booker_does_not_flip_back_to_the_number_owner(): void
    {
        $matt = Customer::create(['name' => 'Matt Cannell', 'phone' => '+447949726109', 'email' => 'cannellmatt@aol.com']);
        $b = app(BookingService::class)->createFromForm($this->data(['customer_name' => 'Matt Cannell', 'customer_email' => 'cannellmatt@aol.com', 'lead_name' => null, 'journey_type' => 'return', 'return_pickup_at' => now()->addDays(9)->format('Y-m-d H:i')]), null);
        $this->assertSame($matt->id, $b->customer_id);

        $b = app(BookingService::class)->updateFromForm($b->fresh(), $this->data());
        $this->assertSame('Laura Ann Lawhern', $b->fresh()->customer->name);
        $this->assertSame('lauraann@jointmedica.com', $b->fresh()->customer->email);
        $this->assertSame('Matt Cannell', $matt->fresh()->name);
        if ($b->linked_booking_id) {
            $this->assertSame($b->fresh()->customer_id, Booking::find($b->linked_booking_id)->customer_id, 'other leg follows');
        }
    }
}
