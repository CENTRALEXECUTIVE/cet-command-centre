<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\ImpersonationController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "View as driver" — an admin can impersonate a driver for support and switch back.
 */
class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_view_as_a_driver_and_return(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->create(['role' => 'driver']);

        $this->actingAs($admin)->post(route('impersonate.start', $driver))->assertRedirect();
        $this->assertSame($driver->id, auth()->id());
        $this->assertSame($admin->id, session(ImpersonationController::KEY));

        $this->post(route('impersonate.stop'))->assertRedirect(route('users.index'));
        $this->assertSame($admin->id, auth()->id());
        $this->assertNull(session(ImpersonationController::KEY));
    }

    public function test_an_admin_cannot_impersonate_another_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('impersonate.start', $other))->assertForbidden();
    }

    public function test_a_driver_cannot_impersonate(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $target = User::factory()->create(['role' => 'driver']);

        $this->actingAs($driver)->post(route('impersonate.start', $target))->assertForbidden();
    }
}
