<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Mail\CustomerPasswordResetMail;
use Tests\TestCase;

/**
 * Self-service password login for customers on the My Account widget. This is a
 * lightweight login layered on the customer record — it never creates a system
 * user, so a customer can never reach the admin/dispatch app.
 */
class CustomerPasswordAuthTest extends TestCase
{
    use RefreshDatabase;

    private function customerWithPassword(string $email = 'olivia@example.com', string $pass = 'secret123'): Customer
    {
        $c = Customer::create(['name' => 'Olivia Lester', 'email' => $email, 'phone' => '07100000000']);
        $c->setLoginPassword($pass);

        return $c->fresh();
    }

    public function test_password_is_hashed_and_never_plaintext(): void
    {
        $c = $this->customerWithPassword();
        $this->assertNotSame('secret123', $c->password);
        $this->assertTrue(Hash::check('secret123', $c->password));
        $this->assertTrue($c->checkPassword('secret123'));
        $this->assertFalse($c->checkPassword('wrong'));
    }

    public function test_customer_can_sign_in_with_email_and_password(): void
    {
        $this->customerWithPassword();

        $this->post(route('widget.account.login'), [
            'email' => 'olivia@example.com',
            'password' => 'secret123',
        ])->assertRedirect(route('widget.account'));

        $this->assertNotNull(session('widget_customer_id'));

        // The account page now shows their bookings view (verified).
        $this->get(route('widget.account'))->assertOk()->assertSee('My bookings');
    }

    public function test_wrong_password_is_rejected_with_a_generic_error(): void
    {
        $this->customerWithPassword();

        $this->from(route('widget.account'))
            ->post(route('widget.account.login'), ['email' => 'olivia@example.com', 'password' => 'nope'])
            ->assertRedirect(route('widget.account'))
            ->assertSessionHas('account_error');

        $this->assertNull(session('widget_customer_id'));
    }

    public function test_unknown_email_is_rejected_without_revealing_anything(): void
    {
        $this->from(route('widget.account'))
            ->post(route('widget.account.login'), ['email' => 'nobody@example.com', 'password' => 'whatever'])
            ->assertRedirect(route('widget.account'))
            ->assertSessionHas('account_error');
    }

    public function test_a_verified_customer_can_set_a_password(): void
    {
        $c = Customer::create(['name' => 'Sam', 'email' => 'sam@example.com', 'phone' => '07222222222']);

        // Sign in via the reference-free session (simulate verified state).
        $this->withSession(['widget_customer_id' => $c->id])
            ->post(route('widget.account.set-password'), [
                'password' => 'brandnew1',
                'password_confirmation' => 'brandnew1',
            ])->assertRedirect();

        $this->assertTrue($c->fresh()->checkPassword('brandnew1'));
    }

    public function test_set_password_requires_being_verified(): void
    {
        $this->post(route('widget.account.set-password'), [
            'password' => 'brandnew1', 'password_confirmation' => 'brandnew1',
        ])->assertForbidden();
    }

    public function test_forgot_password_sends_a_reset_link(): void
    {
        Mail::fake();
        $this->customerWithPassword();

        $this->post(route('widget.account.send-reset'), ['email' => 'olivia@example.com'])
            ->assertOk()->assertSee('reset link is on its way', false);

        Mail::assertSent(CustomerPasswordResetMail::class, fn ($m) => $m->hasTo('olivia@example.com'));
    }

    public function test_forgot_password_is_neutral_for_unknown_email(): void
    {
        Mail::fake();

        $this->post(route('widget.account.send-reset'), ['email' => 'ghost@example.com'])
            ->assertOk()->assertSee('reset link is on its way', false);

        Mail::assertNothingSent();
    }

    public function test_reset_link_with_a_valid_token_sets_a_new_password_and_signs_in(): void
    {
        $c = $this->customerWithPassword();
        $raw = $c->startPasswordReset();

        $this->get(route('widget.account.reset.show', ['token' => $raw, 'email' => $c->email]))
            ->assertOk()->assertSee('Set a new password');

        $this->post(route('widget.account.reset'), [
            'email' => $c->email,
            'token' => $raw,
            'password' => 'freshpass9',
            'password_confirmation' => 'freshpass9',
        ])->assertOk()->assertSee('Password updated');

        $this->assertTrue($c->fresh()->checkPassword('freshpass9'));
        $this->assertNotNull(session('widget_customer_id'));
        // Token is single-use — cleared once the password is set.
        $this->assertNull($c->fresh()->password_reset_token);
    }

    public function test_reset_with_an_invalid_token_is_refused(): void
    {
        $c = $this->customerWithPassword();
        $c->startPasswordReset();

        $this->post(route('widget.account.reset'), [
            'email' => $c->email,
            'token' => 'not-the-real-token',
            'password' => 'freshpass9',
            'password_confirmation' => 'freshpass9',
        ])->assertOk()->assertSee('invalid or has expired');

        $this->assertTrue($c->fresh()->checkPassword('secret123'), 'Password must be unchanged.');
    }

    public function test_reset_with_an_expired_token_is_refused(): void
    {
        $c = $this->customerWithPassword();
        $raw = $c->startPasswordReset(60);
        // Force the token into the past.
        $c->forceFill(['password_reset_expires_at' => now()->subMinute()])->save();

        $this->post(route('widget.account.reset'), [
            'email' => $c->email,
            'token' => $raw,
            'password' => 'freshpass9',
            'password_confirmation' => 'freshpass9',
        ])->assertOk()->assertSee('invalid or has expired');

        $this->assertTrue($c->fresh()->checkPassword('secret123'));
    }

    public function test_open_account_personal_signup_can_set_a_login_password(): void
    {
        $this->post(route('widget.open-account.store'), [
            'account_type' => 'personal',
            'personal_name' => 'Priya Shah',
            'personal_email' => 'priya@example.com',
            'personal_phone' => '07333333333',
            'password' => 'welcome12',
            'password_confirmation' => 'welcome12',
        ])->assertOk();

        $c = Customer::where('email', 'priya@example.com')->first();
        $this->assertNotNull($c);
        $this->assertTrue($c->checkPassword('welcome12'));
    }

    public function test_open_account_company_signup_gives_the_main_contact_a_login(): void
    {
        $this->post(route('widget.open-account.store'), [
            'account_type' => 'company',
            'company_name' => 'Forged Solutions',
            'company_address' => '2 Mill Lane, Sheffield',
            'contact_name' => 'Dana Corp',
            'contact_email' => 'dana@forged.example',
            'contact_phone' => '07444444444',
            'password' => 'company123',
            'password_confirmation' => 'company123',
        ])->assertOk();

        $c = Customer::where('email', 'dana@forged.example')->first();
        $this->assertNotNull($c, 'The main contact should get a customer login.');
        $this->assertTrue($c->checkPassword('company123'));
        $this->assertNotNull($c->corporate_account_id, 'The login is linked to the pending company.');
    }

    public function test_password_mismatch_is_rejected_on_signup(): void
    {
        $this->post(route('widget.open-account.store'), [
            'account_type' => 'personal',
            'personal_name' => 'Mismatch',
            'personal_email' => 'mismatch@example.com',
            'personal_phone' => '07555555555',
            'password' => 'abcdefgh',
            'password_confirmation' => 'zzzzzzzz',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('customers', ['email' => 'mismatch@example.com']);
    }
}
