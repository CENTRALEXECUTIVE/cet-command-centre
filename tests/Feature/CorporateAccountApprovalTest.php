<?php

namespace Tests\Feature;

use App\Models\CorporateAccount;
use App\Models\CorporateContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** 27 Nov 2026: a self-registered business account could not be approved from the app. */
class CorporateAccountApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function pending(): CorporateAccount
    {
        $a = CorporateAccount::create([
            'name' => 'Example Works Ltd', 'account_code' => '2001', 'slug' => 'example-works',
            'is_active' => false, 'payment_terms_days' => 30, 'billing_email' => 'accounts@example-works.co.uk',
        ]);
        CorporateContact::create(['corporate_account_id' => $a->id, 'name' => 'Sam Contact', 'email' => 'sam@example-works.co.uk', 'is_primary' => true]);

        return $a;
    }

    public function test_the_office_approves_a_pending_account_and_the_contact_is_emailed_once(): void
    {
        Mail::fake();
        $a = $this->pending();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('accounts.show', $a))->assertOk()->assertSee('Pending approval')->assertSee('Approve account');
        $this->actingAs($admin)->post(route('accounts.approve', $a))->assertRedirect()->assertSessionHas('status');
        $this->assertTrue($a->fresh()->is_active);
        Mail::assertSent(\App\Mail\CustomerNoticeMail::class, fn ($m) => $m->hasTo('sam@example-works.co.uk'));

        // Pressing again doesn't email again.
        $this->actingAs($admin)->post(route('accounts.approve', $a));
        Mail::assertSent(\App\Mail\CustomerNoticeMail::class, 1);

        $this->actingAs($admin)->get(route('accounts.show', $a))->assertSee('Suspend');
    }

    public function test_the_office_can_suspend_an_account_without_emailing(): void
    {
        Mail::fake();
        $a = $this->pending();
        $a->forceFill(['is_active' => true])->save();
        $this->actingAs(User::factory()->admin()->create())->post(route('accounts.suspend', $a))->assertRedirect();
        $this->assertFalse($a->fresh()->is_active);
        Mail::assertNothingSent();
    }

    public function test_a_driver_cannot_approve(): void
    {
        $a = $this->pending();
        $this->actingAs(User::factory()->create())->post(route('accounts.approve', $a))->assertForbidden();
        $this->assertFalse($a->fresh()->is_active);
    }

    public function test_customer_and_account_pages_show_account_and_login_status(): void
    {
        $a = $this->pending();
        $c = \App\Models\Customer::create(['name' => 'Sam Contact', 'email' => 'sam@example-works.co.uk', 'phone' => '07700900333']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('customers.show', $c))->assertOk()
            ->assertSee('Example Works Ltd')->assertSee("can't book on account yet", false)->assertSee('not set');
        $this->actingAs($admin)->get(route('accounts.show', $a))->assertOk()->assertSee('Online login')->assertSee('Not yet');

        $a->forceFill(['is_active' => true])->save();
        $c->setLoginPassword('a-good-password');
        $this->actingAs($admin)->get(route('customers.show', $c))->assertSee('Active — can book on account');
        $this->actingAs($admin)->get(route('accounts.show', $a))->assertSee('Can sign in');
    }
}
