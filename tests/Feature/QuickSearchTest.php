<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The top search box: type anything, results pop up grouped (directors, 16 Nov 2026). */
class QuickSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_finds_bookings_customers_and_drivers_by_anything(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->create(['role' => 'driver', 'name' => 'Jabir Driver']);
        $c = Customer::create(['name' => 'Justin Hall', 'phone' => '07917 467476', 'email' => 'justin@example.com']);
        Booking::factory()->create(['customer_id' => $c->id, 'driver_id' => $driver->id, 'pickup_at' => now()->addDays(4),
            'pickup_address' => '11 Hayfield Close, Dronfield S18 8RP', 'destination_address' => 'Holiday Inn Manchester Airport',
            'flight_number' => 'EZY708', 'reference' => 'CET-975569']);

        $hit = fn (string $q) => collect($this->actingAs($admin)->getJson(route('quick-search', ['q' => $q]))->assertOk()->json('groups'))
            ->mapWithKeys(fn ($g) => [$g['label'] => collect($g['items'])->pluck('title')->implode(' | ')]);

        $this->assertStringContainsString('Justin Hall', $hit('justin')['Customers']);
        $this->assertStringContainsString('CET-975569', $hit('hayfield')['Bookings']);
        $this->assertStringContainsString('CET-975569', $hit('ezy708')['Bookings']);
        $this->assertStringContainsString('Justin Hall', $hit('+44 7917 467476')['Customers']);
        $this->assertStringContainsString('Jabir Driver', $hit('jabir')['Drivers']);
        $this->assertSame([], $this->actingAs($admin)->getJson(route('quick-search', ['q' => 'zzzznothing']))->json('groups'));
    }

    public function test_drivers_cannot_use_it(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'driver']))
            ->getJson(route('quick-search', ['q' => 'justin']))->assertForbidden();
    }
}
