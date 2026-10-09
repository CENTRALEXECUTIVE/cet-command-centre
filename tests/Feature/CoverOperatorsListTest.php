<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The cover-job form offers previously-used operators as a pick list (with their
 * saved email/phone) so the office doesn't retype the same operator on every job.
 */
class CoverOperatorsListTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function coverJob(string $operator, ?string $email, ?string $phone, string $when): Booking
    {
        $b = Booking::factory()->create(['pickup_at' => $when]);
        $b->forceFill(['meta' => ['cover_for' => array_filter([
            'name' => $operator, 'email' => $email, 'phone' => $phone, 'amount' => 90,
        ], fn ($v) => $v !== null)]])->save();

        return $b;
    }

    public function test_it_lists_distinct_operators_with_contact_details(): void
    {
        $this->coverJob('Xclusive Chauffeur Services', 'info@xclusive.co.uk', '+447497363737', now()->subDays(3));
        $this->coverJob('A1 Cars Ltd', 'accounts@a1.co.uk', '07111222333', now()->subDay());

        $res = $this->actingAs($this->admin())->getJson(route('cover-invoices.operators'));

        $res->assertOk();
        $ops = collect($res->json('operators'));
        $this->assertCount(2, $ops);
        $x = $ops->firstWhere('name', 'Xclusive Chauffeur Services');
        $this->assertSame('info@xclusive.co.uk', $x['email']);
        $this->assertSame('+447497363737', $x['phone']);
    }

    public function test_the_same_operator_is_listed_once_with_latest_details(): void
    {
        // Same operator covered twice; the newer job has the up-to-date email.
        $this->coverJob('A1 Cars Ltd', 'old@a1.co.uk', '07000000000', now()->subDays(10));
        $this->coverJob('A1 Cars Ltd', 'new@a1.co.uk', '07999999999', now()->subDay());

        $ops = collect($this->actingAs($this->admin())->getJson(route('cover-invoices.operators'))->json('operators'));

        $this->assertCount(1, $ops);
        $this->assertSame('new@a1.co.uk', $ops->first()['email']);
        $this->assertSame('07999999999', $ops->first()['phone']);
    }

    public function test_a_newer_blank_detail_keeps_the_older_one(): void
    {
        // Newer job left the email blank — keep the email from the earlier job.
        $this->coverJob('A1 Cars Ltd', 'keep@a1.co.uk', '07123123123', now()->subDays(5));
        $this->coverJob('A1 Cars Ltd', null, null, now()->subDay());

        $ops = collect($this->actingAs($this->admin())->getJson(route('cover-invoices.operators'))->json('operators'));

        $this->assertSame('keep@a1.co.uk', $ops->first()['email']);
        $this->assertSame('07123123123', $ops->first()['phone']);
    }

    public function test_non_admins_are_forbidden(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson(route('cover-invoices.operators'))
            ->assertForbidden();
    }
}
