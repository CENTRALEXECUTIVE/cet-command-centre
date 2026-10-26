<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The admin booking form can set DIFFERENT return-leg addresses (a return isn't
 * always a straight reverse), and can preview the customer confirmation email
 * before the booking is confirmed.
 */
class AdminReturnAddressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'John Mannix',
            'customer_phone' => '07700900123',
            'customer_email' => 'john@example.com',
            'vehicle_type_id' => VehicleType::first()->id,
            'journey_type' => 'return',
            'pickup_at' => now()->addDays(2)->format('Y-m-d\TH:i'),
            'return_pickup_at' => now()->addDays(3)->format('Y-m-d\TH:i'),
            'pickup_address' => 'Sheffield Station',
            'destination_address' => 'Cavendish Hotel, Baslow',
            'passengers' => 2,
            'payment_method' => 'card',
            'quoted_price' => 110.00,
            'privacy_consent' => '1',
        ], $overrides);
    }

    public function test_a_return_defaults_to_the_reverse_of_the_outbound(): void
    {
        Mail::fake();
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.store'), $this->payload())
            ->assertRedirect();

        $return = Booking::where('is_return_leg', true)->firstOrFail();
        $this->assertSame('Cavendish Hotel, Baslow', $return->pickup_address);
        $this->assertSame('Sheffield Station', $return->destination_address);
    }

    public function test_the_office_can_set_different_return_addresses(): void
    {
        Mail::fake();
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.store'), $this->payload([
                'return_pickup_address' => 'Chatsworth House, Bakewell',
                'return_destination_address' => 'Sheffield Airport Hotel',
            ]))
            ->assertRedirect();

        $return = Booking::where('is_return_leg', true)->firstOrFail();
        // The return uses the office's addresses, not a blind reverse of the outbound.
        $this->assertSame('Chatsworth House, Bakewell', $return->pickup_address);
        $this->assertSame('Sheffield Airport Hotel', $return->destination_address);

        // And the two legs are still linked as one trip.
        $out = Booking::where('is_return_leg', false)->firstOrFail();
        $this->assertSame($out->id, $return->linked_booking_id);
        $this->assertSame($return->id, $out->linked_booking_id);
    }

    public function test_the_confirmation_email_can_be_previewed_before_saving(): void
    {
        $res = $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.confirmation-email.preview-new'), $this->payload())
            ->assertOk();

        // It renders the real email from the entered details, and saves NOTHING.
        $res->assertSee('John Mannix', false);
        $res->assertSee('Cavendish Hotel, Baslow', false);
        $this->assertSame(0, Booking::count(), 'previewing must not create a booking');
    }

    public function test_the_new_booking_preview_is_admin_only(): void
    {
        $this->actingAs(User::factory()->driver()->create())
            ->post(route('bookings.confirmation-email.preview-new'), $this->payload())
            ->assertForbidden();
    }
}
