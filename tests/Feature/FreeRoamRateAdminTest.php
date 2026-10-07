<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Pricing\FreeRoamPricer;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Office "super control" of the distance-based fares. Rates and the estate uplift
 * live in Settings and flow straight into FreeRoamPricer, so a saved change
 * reprices customer quotes with no code deploy. Prices are ex-VAT.
 */
class FreeRoamRateAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([VehicleTypeSeeder::class]);
    }

    public function test_defaults_apply_when_nothing_is_saved(): void
    {
        $pricer = app(FreeRoamPricer::class);
        // Executive 25 miles, ex-VAT: 50 + 15*2.00 = 80, rounds to 80. VAT is added
        // separately only when requested.
        $this->assertSame(80.0, $pricer->price('executive', 25));
    }

    public function test_admin_can_change_a_rate_and_it_reprices(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('free-roam.index'))->assertOk()->assertSee('Free-roam rates');

        $this->actingAs($admin)->put(route('free-roam.update'), [
            'rates' => [
                'executive' => ['flat' => 60, 'tier1' => 2.50, 'tier2' => 2.00],
                'minibus-8' => ['flat' => 70, 'tier1' => 2.23, 'tier2' => 2.15],
                'minibus-8-xl' => ['flat' => 90, 'tier1' => 2.23, 'tier2' => 2.15],
                'v-class' => ['flat' => 100, 'tier1' => 2.73, 'tier2' => 2.65],
            ],
            'estate_uplift' => 15,
        ])->assertRedirect();

        $pricer = app(FreeRoamPricer::class);
        // Executive 25 mi now, ex-VAT: 60 + 15*2.50 = 97.5, rounds to 100 (nearest £5).
        $this->assertSame(100.0, $pricer->price('executive', 25));
        // Estate follows Executive + its (edited) uplift.
        $this->assertSame(115.0, $pricer->price('estate', 25));
    }

    public function test_a_partial_save_leaves_other_vehicles_on_their_defaults(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put(route('free-roam.update'), [
            'rates' => [
                'executive' => ['flat' => 60, 'tier1' => 2.00, 'tier2' => 1.73],
                // v-class deliberately not posted below defaults; controller still
                // writes only the posted slugs, defaults fill the rest.
                'minibus-8' => ['flat' => 70, 'tier1' => 2.23, 'tier2' => 2.15],
                'minibus-8-xl' => ['flat' => 90, 'tier1' => 2.23, 'tier2' => 2.15],
                'v-class' => ['flat' => 100, 'tier1' => 2.73, 'tier2' => 2.65],
            ],
            'estate_uplift' => 10,
        ])->assertRedirect();

        $pricer = app(FreeRoamPricer::class);
        // v-class unchanged from default: 5mi = flat 100 ex-VAT.
        $this->assertSame(100.0, $pricer->price('v-class', 5));
    }

    public function test_a_non_admin_cannot_manage_free_roam_rates(): void
    {
        $client = User::factory()->corporateClient()->create();
        $this->actingAs($client)->get(route('free-roam.index'))->assertForbidden();
        $this->actingAs($client)->put(route('free-roam.update'), [
            'rates' => ['executive' => ['flat' => 1, 'tier1' => 1, 'tier2' => 1]],
            'estate_uplift' => 0,
        ])->assertForbidden();

        // Untouched — still the default (ex-VAT).
        $this->assertSame(80.0, app(FreeRoamPricer::class)->price('executive', 25));
    }
}
