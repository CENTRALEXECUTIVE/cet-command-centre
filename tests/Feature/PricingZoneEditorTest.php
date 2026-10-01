<?php

namespace Tests\Feature;

use App\Models\PricingZone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The office can manage pricing zones and the postcodes they cover (e.g. carve out a
 * dearer "deep" area), from the admin zones editor.
 */
class PricingZoneEditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_add_a_zone_with_postcodes(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('zones.store'), [
            'name' => 'Barnsley Deep', 'postcode_prefixes' => 's71, s72 S73',
        ])->assertRedirect();

        $zone = PricingZone::where('slug', 'barnsley-deep')->first();
        $this->assertNotNull($zone);
        $this->assertSame(['S71', 'S72', 'S73'], $zone->postcode_prefixes);
        $this->assertTrue($zone->is_active);
    }

    public function test_an_admin_can_edit_a_zones_postcodes_and_active_flag(): void
    {
        $admin = User::factory()->admin()->create();
        $zone = PricingZone::create(['name' => 'Worksop', 'slug' => 'worksop', 'postcode_prefixes' => ['S80'], 'is_active' => true]);

        $this->actingAs($admin)->put(route('zones.update', $zone), [
            'name' => 'Worksop', 'postcode_prefixes' => 'S80, S81',
            // is_active omitted → unchecked → deactivated
        ])->assertRedirect();

        $zone->refresh();
        $this->assertSame(['S80', 'S81'], $zone->postcode_prefixes);
        $this->assertFalse($zone->is_active);
    }

    public function test_a_duplicate_name_gets_a_unique_slug(): void
    {
        $admin = User::factory()->admin()->create();
        PricingZone::create(['name' => 'Deep', 'slug' => 'deep', 'postcode_prefixes' => [], 'is_active' => true]);

        $this->actingAs($admin)->post(route('zones.store'), ['name' => 'Deep'])->assertRedirect();

        $this->assertSame(2, PricingZone::where('name', 'Deep')->count());
        $this->assertTrue(PricingZone::where('slug', 'deep-2')->exists());
    }

    public function test_a_driver_cannot_manage_zones(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->get(route('zones.index'))->assertForbidden();
        $this->actingAs($driver)->post(route('zones.store'), ['name' => 'X'])->assertForbidden();
    }
}
