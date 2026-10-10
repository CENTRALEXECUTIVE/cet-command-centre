<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The admin "£1 test card link" proves the live card flow end to end. It charges
 * for real via the booking's provider but uses a TEST- reference, so paying it
 * NEVER marks the booking paid — nothing to clean up in the app.
 */
class TestCardLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_vat_booking_gets_a_square_test_link(): void
    {
        config(['services.square.access_token' => 'sq_tok', 'services.square.location_id' => 'LOC', 'services.square.environment' => 'sandbox']);
        Http::fake(['connect.squareupsandbox.com/*' => Http::response(['payment_link' => ['url' => 'https://square.link/test']], 200)]);

        $booking = Booking::factory()->create();
        $booking->forceFill(['meta' => ['vat_invoice_requested' => true]])->save();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.transactions.test-link', $booking))
            ->assertRedirect()
            ->assertSessionHas('copy_link', 'https://square.link/test');

        // Reference is a throwaway TEST-, never FARE-, so no booking is marked paid.
        Http::assertSent(fn ($req) => str_contains(json_encode($req->data()), 'TEST-')
            && ! str_contains(json_encode($req->data()), 'FARE-'));
        $this->assertNotSame('paid', $booking->fresh()->payment_status);
    }

    public function test_a_novat_booking_gets_a_stripe_test_link(): void
    {
        config(['services.stripe.secret_key' => 'sk_test']);
        Http::fake(['api.stripe.com/*' => Http::response(['url' => 'https://checkout.stripe.com/test'], 200)]);

        $booking = Booking::factory()->create();
        $booking->forceFill(['meta' => ['vat_invoice_requested' => false, 'billing_entity' => 'chauffeurs']])->save();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.transactions.test-link', $booking))
            ->assertRedirect()
            ->assertSessionHas('copy_link', 'https://checkout.stripe.com/test');
    }

    public function test_it_errors_when_no_provider_is_connected(): void
    {
        config(['services.square.access_token' => null, 'services.square.location_id' => null, 'services.stripe.secret_key' => null, 'services.square_chauffeurs.access_token' => null]);
        $booking = Booking::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.transactions.test-link', $booking))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_non_admins_are_forbidden(): void
    {
        $booking = Booking::factory()->create();
        $this->actingAs(User::factory()->driver()->create())
            ->post(route('bookings.transactions.test-link', $booking))
            ->assertForbidden();
    }
}
