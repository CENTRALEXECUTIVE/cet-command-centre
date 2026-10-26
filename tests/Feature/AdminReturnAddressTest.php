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
        // Mirror live: Square (tips) configured, so the email builds a tip link —
        // which must NOT try to save the unsaved preview booking (the staging bug).
        config(['services.square.access_token' => 'sq', 'services.square.location_id' => 'LOC']);

        $res = $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.confirmation-email.preview-new'), $this->payload())
            ->assertOk();

        // It renders the real email from the entered details, and saves NOTHING.
        $res->assertSee('John Mannix', false);
        $res->assertSee('Cavendish Hotel, Baslow', false);
        // A return preview shows THIS leg's fare (£110 trip → £55 per leg), not £110.
        $res->assertSee('55.00', false);
        $res->assertDontSee('110.00', false);
        $this->assertSame(0, Booking::count(), 'previewing must not create a booking');
    }

    public function test_the_new_booking_preview_is_admin_only(): void
    {
        $this->actingAs(User::factory()->driver()->create())
            ->post(route('bookings.confirmation-email.preview-new'), $this->payload())
            ->assertForbidden();
    }

    public function test_a_return_stores_each_leg_s_own_fare(): void
    {
        Mail::fake();
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.store'), $this->payload([
                'quoted_price' => 110,
                'leg_price_out' => 55,
                'leg_price_return' => 55,
            ]))
            ->assertRedirect();

        $out = Booking::where('is_return_leg', false)->firstOrFail();
        $ret = Booking::where('is_return_leg', true)->firstOrFail();
        // £55 on each leg — not £110 on the outbound and nothing on the return.
        $this->assertEqualsWithDelta(55.0, (float) $out->quoted_price, 0.01);
        $this->assertEqualsWithDelta(55.0, (float) $ret->quoted_price, 0.01);
        // The whole trip still totals £110.
        $this->assertEqualsWithDelta(110.0, (float) $out->invoiceGroupBookings()->sum(fn ($b) => (float) $b->fareGross()), 0.01);
    }

    public function test_a_custom_total_splits_in_the_quote_ratio(): void
    {
        Mail::fake();
        // Quote was 60/40; the office types a £120 total → split stays 60/40 = 72/48.
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.store'), $this->payload([
                'quoted_price' => 120,
                'leg_price_out' => 60,
                'leg_price_return' => 40,
            ]))
            ->assertRedirect();

        $out = Booking::where('is_return_leg', false)->firstOrFail();
        $ret = Booking::where('is_return_leg', true)->firstOrFail();
        $this->assertEqualsWithDelta(72.0, (float) $out->quoted_price, 0.01);
        $this->assertEqualsWithDelta(48.0, (float) $ret->quoted_price, 0.01);
    }

    public function test_a_return_emails_both_legs_each_with_a_pay_link(): void
    {
        Mail::fake();
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.store'), $this->payload([
                'email_customer' => '1',
                'quoted_price' => 110, 'leg_price_out' => 55, 'leg_price_return' => 55,
            ]))
            ->assertRedirect();

        // Two confirmations — one per leg — each carrying a Pay now link.
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\BookingConfirmationMail::class, 2);
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\BookingConfirmationMail::class,
            fn ($m) => ! $m->booking->is_return_leg && $m->payUrl !== null);
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\BookingConfirmationMail::class,
            fn ($m) => $m->booking->is_return_leg && $m->payUrl !== null);
    }
}
