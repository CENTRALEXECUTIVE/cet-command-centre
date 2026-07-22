<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Remember me on this device": ticking the box on login issues a long-lived
 * cookie so the user is taken straight in next time — no re-login — until they
 * sign out.
 */
class RememberMeTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticking_remember_issues_a_long_lived_cookie(): void
    {
        $user = User::factory()->admin()->create();

        $response = $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
            'remember' => 'on',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        // A persistent remember cookie is set, lasting well over a year.
        $cookie = collect($response->headers->getCookies())
            ->first(fn ($c) => str_starts_with($c->getName(), 'remember_web'));
        $this->assertNotNull($cookie, 'Ticking remember me should issue a remember_web cookie.');
        $this->assertGreaterThan(now()->addYear()->timestamp, $cookie->getExpiresTime());

        // And the token is persisted so the cookie can re-authenticate later.
        $this->assertNotNull($user->fresh()->remember_token);
    }

    public function test_leaving_remember_unticked_does_not_persist(): void
    {
        $user = User::factory()->admin()->create(['remember_token' => null]);

        $response = $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('dashboard'));

        $cookie = collect($response->headers->getCookies())
            ->first(fn ($c) => str_starts_with($c->getName(), 'remember_web'));
        $this->assertNull($cookie, 'Without remember me, no persistent cookie should be set.');
        $this->assertNull($user->fresh()->remember_token);
    }
}
