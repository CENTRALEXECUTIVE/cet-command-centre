<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A freshly issued or admin-reset login must set their own password before they
 * can use the app — but can still log out and reach the change page itself.
 */
class ForcedPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_flagged_user_is_redirected_to_set_a_password(): void
    {
        $user = User::factory()->admin()->create(['must_change_password' => true]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertRedirect(route('password.change'));

        // The change page itself is reachable (not a redirect loop).
        $this->actingAs($user)->get(route('password.change'))->assertOk();
    }

    public function test_setting_a_new_password_clears_the_flag_and_lets_them_in(): void
    {
        $user = User::factory()->driver()->create(['must_change_password' => true]);

        $this->actingAs($user)->put(route('password.update'), [
            'password' => 'my-own-pass-9',
            'password_confirmation' => 'my-own-pass-9',
        ])->assertRedirect(route('dashboard'));

        $fresh = $user->fresh();
        $this->assertFalse($fresh->must_change_password);
        $this->assertTrue(Hash::check('my-own-pass-9', $fresh->password));

        // No longer gated.
        $this->actingAs($fresh)->get(route('dashboard'))->assertOk();
    }

    public function test_mismatched_confirmation_is_rejected(): void
    {
        $user = User::factory()->driver()->create(['must_change_password' => true]);

        $this->actingAs($user)->put(route('password.update'), [
            'password' => 'my-own-pass-9',
            'password_confirmation' => 'different',
        ])->assertSessionHasErrors('password');

        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_an_unflagged_user_is_not_gated(): void
    {
        $user = User::factory()->admin()->create(['must_change_password' => false]);

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }
}
