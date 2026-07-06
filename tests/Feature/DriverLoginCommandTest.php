<?php

namespace Tests\Feature;

use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DriverLoginCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_sets_a_driver_password_by_callsign(): void
    {
        $driver = User::factory()->create(['role' => 'driver', 'name' => 'Majid Ali', 'email' => 'maj@cet.test', 'is_active' => false]);
        DriverProfile::create(['user_id' => $driver->id, 'callsign' => 'Majid']);

        $this->artisan('cet:driver-login', ['name' => 'Maj', '--password' => 'drive1234'])
            ->assertSuccessful();

        $driver->refresh();
        $this->assertTrue(Hash::check('drive1234', $driver->password));
        $this->assertTrue($driver->is_active); // reactivated so they can log in
    }

    public function test_unknown_driver_fails_cleanly(): void
    {
        $this->artisan('cet:driver-login', ['name' => 'Nobody'])->assertFailed();
    }
}
