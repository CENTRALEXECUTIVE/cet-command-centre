<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cover invoices can generate a Square card-payment link for the combined total,
 * which is stored on the anchor booking and embedded on the invoice (amount +
 * reference). The link is COVER-tagged so it never marks a booking's fare paid.
 */
class CoverInvoicePaymentLinkTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function coverJob(string $ref, float $amount, string $operator = 'A1 Cars Ltd'): Booking
    {
        $b = Booking::factory()->create(['reference' => $ref, 'pickup_at' => now()->subDay()]);
        $b->forceFill(['meta' => ['cover_for' => ['name' => $operator, 'email' => 'accounts@a1.co.uk', 'amount' => $amount]]])->save();

        return $b->fresh();
    }

    private function enableSquare(): void
    {
        config([
            'services.square.access_token' => 'sq_test_token',
            'services.square.location_id' => 'LOC_TEST',
            'services.square.environment' => 'sandbox',
        ]);
    }

    public function test_it_creates_and_stores_a_payment_link_for_the_combined_total(): void
    {
        $this->enableSquare();
        Http::fake([
            'connect.squareupsandbox.com/*' => Http::response(['payment_link' => ['url' => 'https://square.link/u/abc123']], 200),
        ]);

        $a = $this->coverJob('CET-A', 90);
        $b = $this->coverJob('CET-B', 110);

        $this->actingAs($this->admin())
            ->post(route('cover-invoices.payment-link'), ['ids' => [$a->id, $b->id]])
            ->assertRedirect()
            ->assertSessionHas('cover_payment_link', 'https://square.link/u/abc123');

        // Stored on the anchor (earliest) booking with the combined £200 total.
        $anchor = $a->is($a->fresh()) ? Booking::whereIn('id', [$a->id, $b->id])->orderBy('pickup_at')->first() : $a->fresh();
        $link = $anchor->fresh()->meta['cover_payment_link'] ?? null;
        $this->assertNotNull($link);
        $this->assertSame('https://square.link/u/abc123', $link['url']);
        $this->assertEqualsWithDelta(200.0, $link['amount'], 0.01);
        $this->assertStringStartsWith('CVR-', $link['reference']);

        // The Square order carried the COVER- reference prefix, not FARE-.
        Http::assertSent(fn ($req) => str_contains(json_encode($req->data()), 'COVER-CVR-'));
    }

    public function test_the_ajax_path_returns_the_link_as_json(): void
    {
        $this->enableSquare();
        Http::fake([
            'connect.squareupsandbox.com/*' => Http::response(['payment_link' => ['url' => 'https://square.link/u/xyz']], 200),
        ]);
        $a = $this->coverJob('CET-A', 120);

        $this->actingAs($this->admin())
            ->postJson(route('cover-invoices.payment-link'), ['ids' => [$a->id]])
            ->assertOk()
            ->assertJson(['ok' => true, 'url' => 'https://square.link/u/xyz', 'amount' => '120.00']);
    }

    public function test_the_ajax_path_returns_a_clean_error_when_square_is_off(): void
    {
        config(['services.square.access_token' => null, 'services.square.location_id' => null]);
        $a = $this->coverJob('CET-A', 120);

        $this->actingAs($this->admin())
            ->postJson(route('cover-invoices.payment-link'), ['ids' => [$a->id]])
            ->assertOk()
            ->assertJson(['ok' => false]);
    }

    public function test_it_errors_cleanly_when_square_is_off(): void
    {
        config(['services.square.access_token' => null, 'services.square.location_id' => null]);
        $a = $this->coverJob('CET-A', 90);

        $this->actingAs($this->admin())
            ->post(route('cover-invoices.payment-link'), ['ids' => [$a->id]])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_it_errors_when_the_total_is_zero(): void
    {
        $this->enableSquare();
        $a = $this->coverJob('CET-A', 0);

        $this->actingAs($this->admin())
            ->post(route('cover-invoices.payment-link'), ['ids' => [$a->id]])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_non_admins_are_forbidden(): void
    {
        $a = $this->coverJob('CET-A', 90);
        $this->actingAs(User::factory()->driver()->create())
            ->post(route('cover-invoices.payment-link'), ['ids' => [$a->id]])
            ->assertForbidden();
    }
}
