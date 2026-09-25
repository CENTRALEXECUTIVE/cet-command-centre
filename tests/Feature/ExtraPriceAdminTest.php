<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Surcharges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Office "super control" of the extras price list. Saved overrides merge over the
 * config defaults and flow straight into Surcharges::rates(), so a change reprices
 * quotes with no code deploy.
 */
class ExtraPriceAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_apply_when_nothing_is_saved(): void
    {
        $this->assertSame((float) config('cet.surcharges.child_seat'), Surcharges::rates()['child_seat']);
    }

    public function test_admin_can_change_an_extra_price(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('extras.index'))->assertOk()->assertSee('Meet & greet');

        $rates = Surcharges::rates();
        $rates['child_seat'] = 15;
        $rates['ribbons_car'] = 40;

        $this->actingAs($admin)->put(route('extras.update'), ['extras' => $rates])->assertRedirect();

        $fresh = Surcharges::rates();
        $this->assertSame(15.0, $fresh['child_seat']);
        $this->assertSame(40.0, $fresh['ribbons_car']);
        // An untouched extra keeps its previous value.
        $this->assertSame((float) config('cet.surcharges.meet_greet'), $fresh['meet_greet']);
    }

    public function test_an_extra_can_be_made_free(): void
    {
        $admin = User::factory()->admin()->create();
        $rates = Surcharges::rates();
        $rates['stopover'] = 0;

        $this->actingAs($admin)->put(route('extras.update'), ['extras' => $rates])->assertRedirect();

        $this->assertSame(0.0, Surcharges::rates()['stopover']);
    }

    public function test_a_non_admin_cannot_manage_extras(): void
    {
        $client = User::factory()->corporateClient()->create();
        $this->actingAs($client)->get(route('extras.index'))->assertForbidden();
        $this->actingAs($client)->put(route('extras.update'), ['extras' => ['child_seat' => 999]])->assertForbidden();

        $this->assertSame((float) config('cet.surcharges.child_seat'), Surcharges::rates()['child_seat']);
    }
}
