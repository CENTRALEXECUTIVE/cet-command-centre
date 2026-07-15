<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AirportSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_renders(): void
    {
        $this->get('/login')->assertOk()->assertSee('Command Centre');
    }

    public function test_admin_can_authenticate_and_reach_dashboard(): void
    {
        $user = User::factory()->admin()->create(['password' => 'secret-pass-123']);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret-pass-123',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('dashboard'));
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_awaiting_allocation_counts_only_upcoming_jobs(): void
    {
        $this->seed([VehicleTypeSeeder::class]);
        $admin = User::factory()->admin()->create();
        $vt = \App\Models\VehicleType::first();
        $customer = \App\Models\Customer::create(['name' => 'Test', 'phone' => '07000000000']);

        $make = fn ($pickupAt) => \App\Models\Booking::create([
            'reference' => \App\Models\Booking::generateReference(),
            'customer_id' => $customer->id,
            'vehicle_type_id' => $vt->id,
            'pickup_at' => $pickupAt,
            'pickup_address' => 'A',
            'destination_address' => 'B',
            'passengers' => 1,
            'status' => 'pending',
            'payment_method' => 'card',
        ]);

        $make(now()->subDays(5)); // past pending import → must NOT count
        $make(now()->addDays(2)); // upcoming unallocated → must count

        $response = $this->actingAs($admin)->get(route('dashboard'));

        $response->assertOk();
        $this->assertEquals(1, $response->viewData('pendingCount'), 'only the upcoming pending job counts');
    }

    public function test_monthly_review_reminder_shows_after_the_4th_and_clears_on_import(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-07-10 09:00:00')); // after the 4th
        $admin = User::factory()->admin()->create();

        // No ETO import recorded this cycle → reminder is due.
        $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()->assertSee('Monthly review due', false);

        // A fresh import this month clears it.
        \App\Models\Setting::set('last_eto_import_at', now()->toDateTimeString(), 'string', 'eto');
        $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()->assertDontSee('Monthly review due', false);
    }

    public function test_login_tolerates_a_capitalised_or_padded_email(): void
    {
        // Tablet keyboards auto-capitalise the first letter and can leave a
        // trailing space — the login must still find the account.
        $user = User::factory()->driver()->create([
            'email' => 'kash-am64-far@cet-drivers.local',
            'password' => 'kash12345',
        ]);

        $this->post('/login', [
            'email' => '  Kash-AM64-far@cet-drivers.local ',
            'password' => 'kash12345',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->admin()->create(['password' => 'correct-password']);

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_deactivated_account_cannot_login(): void
    {
        $user = User::factory()->admin()->inactive()->create(['password' => 'secret-pass-123']);

        $this->post('/login', ['email' => $user->email, 'password' => 'secret-pass-123'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_driver_cannot_access_booking_creation(): void
    {
        $driver = User::factory()->driver()->create();

        $this->actingAs($driver)->get(route('bookings.create'))->assertForbidden();
    }

    public function test_admin_can_access_booking_creation(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        $this->seed(AirportSeeder::class);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('bookings.create'))->assertOk()->assertSee('Smart Booking');
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
