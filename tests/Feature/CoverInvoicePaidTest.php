<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Once an operator has paid a cover invoice, the jobs are marked paid so they
 * drop off the "to invoice" list and can't be billed again — either by the
 * office (manual, cash/transfer) or automatically when a card link is paid.
 */
class CoverInvoicePaidTest extends TestCase
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

    public function test_marking_paid_hides_the_jobs_from_the_list_by_default(): void
    {
        $a = $this->coverJob('CET-A', 90);
        $b = $this->coverJob('CET-B', 110);

        $this->actingAs($this->admin())
            ->post(route('cover-invoices.mark-paid'), ['ids' => [$a->id, $b->id]])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertTrue($a->fresh()->isCoverPaid());
        $this->assertSame('manual', $a->fresh()->coverPaidInfo()['provider']);

        // Hidden from the default list…
        $this->actingAs($this->admin())->get(route('cover-invoices.index'))
            ->assertOk()->assertDontSee('CET-A')->assertDontSee('CET-B');

        // …but visible (with a PAID badge) under "show settled".
        $this->actingAs($this->admin())->get(route('cover-invoices.index', ['show_paid' => 1]))
            ->assertOk()->assertSee('CET-A')->assertSee('PAID');
    }

    public function test_an_unpaid_cover_job_still_shows(): void
    {
        $this->coverJob('CET-LIVE', 90);
        $this->actingAs($this->admin())->get(route('cover-invoices.index'))
            ->assertOk()->assertSee('CET-LIVE');
    }

    public function test_reopening_a_paid_job_brings_it_back(): void
    {
        $a = $this->coverJob('CET-A', 90);
        $a->markCoverPaid(['provider' => 'manual']);

        $this->actingAs($this->admin())
            ->post(route('cover-invoices.mark-paid'), ['ids' => [$a->id], 'paid' => '0'])
            ->assertRedirect();

        $this->assertFalse($a->fresh()->isCoverPaid());
        $this->actingAs($this->admin())->get(route('cover-invoices.index'))
            ->assertOk()->assertSee('CET-A');
    }

    public function test_a_paid_stripe_cover_link_auto_settles_all_its_jobs(): void
    {
        config([
            'services.stripe.secret_key' => 'sk_test_123',
            'services.stripe.webhook_secret' => 'whsec_test',
        ]);

        $a = $this->coverJob('CET-A', 140, 'Xclusive');
        $b = $this->coverJob('CET-B', 160, 'Xclusive');
        // The anchor stashes the invoice reference + the ids of every job on it.
        $a->forceFill(['meta' => array_merge($a->meta, [
            'cover_payment_link' => [
                'url' => 'https://buy.stripe.com/x', 'provider' => 'stripe',
                'reference' => 'CVR-XCLUSIVE-2610', 'amount' => 300, 'ids' => [$a->id, $b->id],
            ],
        ])])->save();

        $payload = json_encode([
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_cover', 'payment_status' => 'paid', 'payment_intent' => 'pi_cover',
                'amount_total' => 30000, 'client_reference_id' => 'COVER-CVR-XCLUSIVE-2610',
            ]],
        ]);
        $t = time();
        $sig = 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$payload, 'whsec_test');

        $this->call('POST', '/webhooks/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertOk()->assertJson(['cover' => true]);

        // Both jobs on the invoice are now settled by card.
        $this->assertTrue($a->fresh()->isCoverPaid());
        $this->assertTrue($b->fresh()->isCoverPaid());
        $this->assertSame('stripe', $a->fresh()->coverPaidInfo()['provider']);
    }

    public function test_non_admins_cannot_mark_paid(): void
    {
        $a = $this->coverJob('CET-A', 90);
        $this->actingAs(User::factory()->driver()->create())
            ->post(route('cover-invoices.mark-paid'), ['ids' => [$a->id]])
            ->assertForbidden();
    }
}
