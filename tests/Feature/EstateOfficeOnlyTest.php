<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use App\Models\VehicleType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Directors (11 Nov 2026): Estate is switched off for customers, but the office must
 * still be able to book it from the admin booking form.
 */
class EstateOfficeOnlyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([\Database\Seeders\VehicleTypeSeeder::class, \Database\Seeders\DirectorSeeder::class,
            \Database\Seeders\AirportSeeder::class, \Database\Seeders\RotationSeeder::class]);
    }

    public function test_hidden_estate_is_offered_to_admins_but_not_customers(): void
    {
        $estate = VehicleType::where('slug', 'estate')->firstOrFail();
        $estate->update(['is_active' => false]);
        $hiddenOther = VehicleType::where('slug', '!=', 'estate')->where('slug', '!=', 'executive')->firstOrFail();
        $hiddenOther->update(['is_active' => false]);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('bookings.create'))->assertOk()
            ->assertSee('value="'.$estate->id.'"', false);

        // Estate defaults to Backend only when switched off; other switched-off types stay off.
        $this->assertFalse(VehicleType::forOffice(true)->whereKey($hiddenOther->id)->exists());
        $this->assertTrue(VehicleType::forOffice(true)->whereKey($estate->id)->exists());
        $this->assertFalse(VehicleType::forOffice(false)->whereKey($estate->id)->exists());

        // Customers' public booking pages never list it.
        $this->assertFalse(VehicleType::where('is_active', true)->whereKey($estate->id)->exists());
    }

    public function test_validation_lets_an_admin_book_estate_but_not_another_hidden_type(): void
    {
        $estate = VehicleType::where('slug', 'estate')->firstOrFail();
        $estate->update(['is_active' => false]);
        $hiddenOther = VehicleType::where('slug', 'minibus-8')->firstOrFail();
        $hiddenOther->update(['is_active' => false]);
        $admin = User::factory()->admin()->create();

        $payload = fn ($vt) => [
            'customer_name' => 'Jo Smith', 'customer_phone' => '07700900123',
            'vehicle_type_id' => $vt->id, 'journey_type' => 'one_way',
            'pickup_at' => now()->addDays(2)->format('Y-m-d\\TH:i'),
            'pickup_address' => '12 Fargate, Sheffield', 'destination_address' => 'Manchester Airport',
            'passengers' => 2, 'luggage' => 2, 'payment_method' => 'card', 'privacy_consent' => '1',
        ];

        $this->actingAs($admin)->post(route('bookings.store'), $payload($estate))->assertSessionHasNoErrors();
        $this->assertSame($estate->id, Booking::latest('id')->first()->vehicle_type_id);
        $this->actingAs($admin)->post(route('bookings.store'), $payload($hiddenOther))->assertSessionHasErrors('vehicle_type_id');
    }
}
