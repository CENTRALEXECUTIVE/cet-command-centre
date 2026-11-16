<?php

namespace Tests\Feature;

use App\Models\CorporateAccount;
use App\Models\User;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Picking a business account carries its invoice details for the VAT boxes (16 Nov 2026). */
class CorporateVatAutofillTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_new_booking_form_carries_each_accounts_vat_details(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        CorporateAccount::create(['name' => 'Forged Solutions Group', 'slug' => 'forged', 'account_code' => 'FSG', 'vat_number' => 'GB999888777',
            'billing_address' => "1 Forge Lane\nSheffield S9 1AA", 'is_active' => true]);

        $this->actingAs(User::factory()->admin()->create())->get(route('bookings.create'))->assertOk()
            ->assertSee('data-vat-name="Forged Solutions Group"', false)
            ->assertSee('data-vat-number="GB999888777"', false)
            ->assertSee('data-vat-address="1 Forge Lane, Sheffield S9 1AA"', false);
    }
}
