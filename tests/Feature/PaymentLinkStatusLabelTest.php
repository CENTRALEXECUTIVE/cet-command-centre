<?php

namespace Tests\Feature;

use App\Mail\PaymentLinkMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Services\Payments\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * "Add & create card link" only CREATES a link — the row must say "Link created",
 * not "Link sent", until it actually goes to the customer (email / WhatsApp / SMS).
 */
class PaymentLinkStatusLabelTest extends TestCase
{
    use RefreshDatabase;

    private function booking(): Booking
    {
        $c = Customer::create(['name' => 'Michael Cantor', 'email' => 'm@example.com', 'phone' => '07700900123']);

        return Booking::factory()->create([
            'customer_id' => $c->id, 'source' => 'phone', 'payment_method' => 'cash',
            'payment_status' => 'pending', 'quoted_price' => 105, 'pickup_at' => now()->addDays(2),
        ]);
    }

    public function test_creating_a_link_says_created_and_emailing_it_says_sent(): void
    {
        Mail::fake();
        $this->mock(PaymentGateway::class, fn ($m) => $m->shouldReceive('createCheckoutUrl')->andReturn('https://pay.example/dep'));
        $admin = User::factory()->admin()->create();
        $b = $this->booking();

        $this->actingAs($admin)->post(route('bookings.transactions.store', $b), [
            'amount' => 10, 'method' => 'card', 'status' => 'pending', 'create_link' => 'copy',
        ])->assertRedirect();

        $p = $b->payments()->first();
        $this->assertSame('link_sent', $p->status); // logic unchanged
        $this->assertSame('Link created', $p->statusLabel());
        Mail::assertNothingSent();

        $this->actingAs($admin)->post(route('bookings.transactions.send-link', [$b, $p]), ['channel' => 'email'])->assertRedirect();
        Mail::assertSent(PaymentLinkMail::class);
        $this->assertSame('Link sent (email)', $p->fresh()->statusLabel());
    }

    public function test_opening_whatsapp_with_the_link_marks_it_sent_via_whatsapp(): void
    {
        $this->mock(PaymentGateway::class, fn ($m) => $m->shouldReceive('createCheckoutUrl')->andReturn('https://pay.example/dep'));
        $admin = User::factory()->admin()->create();
        $b = $this->booking();
        $p = $b->payments()->create(['method' => 'card', 'amount' => 10, 'status' => 'pending']);

        $this->actingAs($admin)->post(route('bookings.transactions.send-link', [$b, $p]), ['channel' => 'whatsapp'])
            ->assertSessionHas('share_wa');
        $this->assertSame('Link sent (WhatsApp)', $p->fresh()->statusLabel());
    }

    public function test_the_card_method_names_stripe_for_pvt_and_square_for_ltd(): void
    {
        $b = $this->booking();
        $p = $b->payments()->create(['method' => 'card', 'amount' => 10, 'status' => 'pending']);
        $expected = $b->billingEntity() === 'chauffeurs' ? 'Card / Stripe' : 'Card / Square';
        $this->assertSame($expected, $p->fresh()->methodLabel());
    }

    public function test_preview_shows_the_deposit_email_and_sends_and_creates_nothing(): void
    {
        Mail::fake();
        $this->mock(PaymentGateway::class, fn ($m) => $m->shouldNotReceive('createCheckoutUrl'));
        $admin = User::factory()->admin()->create();
        $b = $this->booking();
        $p = $b->payments()->create(['method' => 'card', 'amount' => 10, 'status' => 'pending']);

        $this->actingAs($admin)->get(route('bookings.transactions.preview-link', [$b, $p]))->assertOk()
            ->assertSee('PREVIEW ONLY', false)
            ->assertSee('£10.00 deposit', false)
            ->assertSee('£95.00', false)
            ->assertSee('m@example.com', false);
        Mail::assertNothingSent();
        $this->assertNull($p->fresh()->tide_payment_link);

        $this->actingAs(User::factory()->driver()->create())
            ->get(route('bookings.transactions.preview-link', [$b, $p]))->assertForbidden();
    }
}
