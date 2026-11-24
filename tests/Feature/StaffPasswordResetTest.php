<?php

namespace Tests\Feature;

use App\Mail\StaffPasswordResetMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Directors (24 Nov 2026): a locked-out director can reset their own password. */
class StaffPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_director_can_reset_and_the_office_inbox_gets_the_link(): void
    {
        Mail::fake();
        config(['cet.recovery_email' => 'office@example.com']);
        $abdi = User::factory()->admin()->create(['email' => 'abdi@example.com', 'is_super_admin' => true]);

        $this->get(route('login'))->assertSee('Forgot password?');
        $this->post(route('password.email'), ['email' => 'abdi@example.com'])->assertRedirect();
        Mail::assertSent(StaffPasswordResetMail::class, fn ($m) => $m->hasTo('abdi@example.com'));
        $link = null;
        Mail::assertSent(StaffPasswordResetMail::class, function ($m) use (&$link) {
            $link = $m->link;

            return $m->hasTo('office@example.com');
        });

        preg_match('#reset-password/([^?]+)#', $link, $mm);
        $this->post(route('password.reset.update'), ['token' => $mm[1], 'email' => 'abdi@example.com',
            'password' => 'NewPass123', 'password_confirmation' => 'NewPass123'])->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('NewPass123', $abdi->fresh()->password));

        // The link is single use.
        $this->post(route('password.reset.update'), ['token' => $mm[1], 'email' => 'abdi@example.com',
            'password' => 'Other1234', 'password_confirmation' => 'Other1234'])->assertSessionHasErrors('email');
    }

    public function test_unknown_email_gives_the_same_answer_and_sends_nothing(): void
    {
        Mail::fake();
        $this->post(route('password.email'), ['email' => 'nobody@example.com'])->assertRedirect()->assertSessionHas('status');
        Mail::assertNothingSent();
    }
}
