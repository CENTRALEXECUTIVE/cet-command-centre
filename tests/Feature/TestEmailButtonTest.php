<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The Settings "Send test email" button confirms outgoing mail works before a
 * real booking — and clearly flags when mail is set to "log" (nothing sent).
 */
class TestEmailButtonTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_warns_when_mail_is_set_to_log(): void
    {
        config(['mail.default' => 'log']);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('settings.test-email'), ['to' => 'me@example.com'])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_it_sends_when_a_real_mailer_is_configured(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('settings.test-email'), ['to' => 'me@example.com'])
            ->assertRedirect()
            ->assertSessionHas('status');
    }

    public function test_non_admins_cannot_send(): void
    {
        $this->actingAs(User::factory()->driver()->create())
            ->post(route('settings.test-email'), ['to' => 'me@example.com'])
            ->assertForbidden();
    }
}
