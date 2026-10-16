<?php

namespace Tests\Feature;

use App\Mail\BookingConfirmationMail;
use App\Models\Booking;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * An office-made (admin form) booking emails the customer a confirmation when the
 * box is ticked and they have an email — deliberate, one at a time. Unticking, or
 * no email on file, sends nothing.
 */
class AdminBookingEmailTest extends TestCase
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
            'customer_name' => 'Test Customer',
            'customer_phone' => '07700900123',
            'customer_email' => 'customer@example.com',
            'vehicle_type_id' => VehicleType::first()->id,
            'journey_type' => 'one_way',
            'pickup_at' => now()->addDays(2)->format('Y-m-d\TH:i'),
            'pickup_address' => '1 Test St, Sheffield',
            'destination_address' => 'Manchester Airport',
            'passengers' => 2,
            'payment_method' => 'card',
            'quoted_price' => 100.00,
            'privacy_consent' => '1',
        ], $overrides);
    }

    public function test_it_emails_the_customer_when_ticked(): void
    {
        Mail::fake();
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.store'), $this->payload(['email_customer' => '1']))
            ->assertRedirect();

        Mail::assertSent(BookingConfirmationMail::class, fn ($m) => $m->hasTo('customer@example.com'));
        $this->assertNotEmpty(Booking::latest('id')->first()->meta['customer_email_sent'] ?? null);
    }

    public function test_the_email_carries_a_pay_now_link_for_an_unpaid_card_booking(): void
    {
        config(['services.square.access_token' => 'sq', 'services.square.location_id' => 'LOC', 'services.square.environment' => 'sandbox']);
        Mail::fake();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.store'), $this->payload(['email_customer' => '1', 'payment_method' => 'card']))
            ->assertRedirect();

        // The pay link is a STABLE signed CET route (widget.pay), not a baked
        // provider URL — so a later price correction is always reflected when the
        // customer clicks it.
        Mail::assertSent(BookingConfirmationMail::class, fn ($m) => $m->payUrl !== null
            && str_contains($m->payUrl, '/widget/pay/')
            && str_contains($m->payUrl, 'signature='));
    }

    public function test_a_cash_booking_email_has_no_pay_link(): void
    {
        config(['services.square.access_token' => 'sq', 'services.square.location_id' => 'LOC']);
        Mail::fake();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.store'), $this->payload(['email_customer' => '1', 'payment_method' => 'cash']))
            ->assertRedirect();

        Mail::assertSent(BookingConfirmationMail::class, fn ($m) => $m->payUrl === null);
    }

    public function test_it_does_not_email_the_customer_when_unticked(): void
    {
        Mail::fake();
        // No email_customer key = unticked checkbox.
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.store'), $this->payload())
            ->assertRedirect();

        // The customer is NOT emailed; the office always is (new-booking notice).
        Mail::assertNotSent(BookingConfirmationMail::class);
        Mail::assertSent(\App\Mail\OfficeBookingMail::class);
    }

    public function test_it_does_not_email_the_customer_without_an_address(): void
    {
        Mail::fake();
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.store'), $this->payload(['email_customer' => '1', 'customer_email' => '']))
            ->assertRedirect();

        // No customer email on file → no customer confirmation (office still notified).
        Mail::assertNotSent(BookingConfirmationMail::class);
    }
}
