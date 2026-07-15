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

    public function test_resetting_a_driver_password_actually_changes_it_and_is_confirmed(): void
    {
        $driver = User::factory()->driver()->create([
            'email' => 'kash-am64-far@cet-drivers.local', 'password' => 'old-password',
        ]);

        $this->actingAs($this->superAdmin())->put(route('users.update', $driver), [
            'name' => 'Kash', 'email' => 'kash-am64-far@cet-drivers.local',
            'role' => 'driver', 'is_active' => 1, 'password' => 'kash12345',
        ])->assertRedirect()->assertSessionHas('status', fn ($m) => str_contains($m, 'kash12345'));

        // The new password works; the old one no longer does.
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('kash12345', $driver->fresh()->password));
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
