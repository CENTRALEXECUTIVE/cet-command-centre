<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->admin()->create(['is_super_admin' => true]);
    }

    public function test_super_admin_can_create_an_admin(): void
    {
        $this->actingAs($this->superAdmin())->post(route('users.store'), [
            'name' => 'New Admin', 'email' => 'newadmin@cet.test', 'role' => 'admin',
            'is_super_admin' => 1, 'password' => 'secret123',
        ])->assertRedirect();

        $u = User::where('email', 'newadmin@cet.test')->first();
        $this->assertEquals('admin', $u->role->value);
        $this->assertTrue($u->is_super_admin);
    }

    public function test_super_admin_can_create_a_driver(): void
    {
        $this->actingAs($this->superAdmin())->post(route('users.store'), [
            'name' => 'A Driver', 'email' => 'driver@cet.test', 'role' => 'driver',
        ])->assertRedirect();
        $this->assertDatabaseHas('users', ['email' => 'driver@cet.test', 'role' => 'driver']);
    }

    public function test_resetting_a_driver_password_changes_it_and_returns_a_copyable_card(): void
    {
        $driver = User::factory()->driver()->create([
            'email' => 'kash-am64-far@cet-drivers.local', 'password' => 'old-password',
            'phone' => '07785729671',
        ]);

        $this->actingAs($this->superAdmin())->put(route('users.update', $driver), [
            'name' => 'Kash', 'email' => 'kash-am64-far@cet-drivers.local', 'phone' => '07785729671',
            'role' => 'driver', 'is_active' => 1, 'password' => 'kash12345',
        ])->assertRedirect()
            ->assertSessionHas('new_credentials', function ($c) {
                return $c['password'] === 'kash12345'
                    && $c['email'] === 'kash-am64-far@cet-drivers.local'
                    && str_starts_with($c['wa_link'], 'https://wa.me/447785729671?text=');
            });

        $fresh = $driver->fresh();
        // The new password works, and the driver must set their own on next login.
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('kash12345', $fresh->password));
        $this->assertTrue($fresh->must_change_password);
    }

    public function test_new_users_are_flagged_to_set_their_own_password(): void
    {
        $this->actingAs($this->superAdmin())->post(route('users.store'), [
            'name' => 'Fresh Driver', 'email' => 'fresh@cet.test', 'role' => 'driver',
        ])->assertRedirect();

        $this->assertTrue(User::where('email', 'fresh@cet.test')->first()->must_change_password);
    }

    public function test_email_is_stored_lowercase(): void
    {
        $this->actingAs($this->superAdmin())->post(route('users.store'), [
            'name' => 'Caps', 'email' => 'MixedCase@CET.Test', 'role' => 'driver',
        ])->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'mixedcase@cet.test']);
    }

    public function test_regular_admin_cannot_create_an_admin(): void
    {
        $admin = User::factory()->admin()->create(['is_super_admin' => false]);
        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'X', 'email' => 'x@cet.test', 'role' => 'admin',
        ])->assertForbidden();
    }

    public function test_regular_admin_can_create_a_driver(): void
    {
        $admin = User::factory()->admin()->create(['is_super_admin' => false]);
        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'D', 'email' => 'd@cet.test', 'role' => 'driver',
        ])->assertRedirect();
        $this->assertDatabaseHas('users', ['email' => 'd@cet.test']);
    }

    public function test_regular_admin_cannot_edit_an_admin(): void
    {
        $admin = User::factory()->admin()->create(['is_super_admin' => false]);
        $other = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('users.edit', $other))->assertForbidden();
    }

    public function test_driver_cannot_access_users(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->get(route('users.index'))->assertForbidden();
    }
}
