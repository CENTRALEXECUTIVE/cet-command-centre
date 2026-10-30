<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdsOfflineConversionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_bookings_with_a_google_click_id_export_in_googles_template(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        $exec = VehicleType::where('slug', 'executive')->first();
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-20 12:00:00'));

        Booking::factory()->forVehicleType($exec)->create([
            'quoted_price' => 115, 'meta' => ['marketing' => ['gclid' => 'Cj0KCQ-test-click']],
        ]);
        // No click id → not exported; cancelled → not exported.
        Booking::factory()->forVehicleType($exec)->create(['quoted_price' => 90]);
        Booking::factory()->forVehicleType($exec)->create([
            'quoted_price' => 70, 'status' => 'cancelled', 'meta' => ['marketing' => ['gclid' => 'Cj0-cancelled']],
        ]);

        $admin = User::factory()->admin()->create();
        $res = $this->actingAs($admin)->get(route('reports.ads.offline-conversions', ['start' => '2026-10-01', 'end' => '2026-10-31']));
        $res->assertOk();
        $csv = $res->streamedContent();

        $lines = array_map('str_getcsv', array_values(array_filter(explode("\n", $csv))));
        $this->assertStringStartsWith('Parameters:TimeZone=', $lines[0][0]);
        $this->assertSame(['Google Click ID', 'Conversion Name', 'Conversion Time', 'Conversion Value', 'Conversion Currency'], $lines[1]);
        $this->assertCount(3, $lines); // the one ad booking only
        $this->assertSame(['Cj0KCQ-test-click', 'CET completed booking', '2026-10-20 12:00:00', '115.00', 'GBP'], $lines[2]);
        $this->assertStringNotContainsString('Cj0-cancelled', $csv);

        // The Ads report page offers the download.
        $this->actingAs($admin)->get(route('reports.ads', ['start' => '2026-10-01', 'end' => '2026-10-31']))
            ->assertOk()->assertSee('Download for Google Ads');
    }

    public function test_a_driver_cannot_download_it(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->get(route('reports.ads.offline-conversions'))->assertForbidden();
    }
}
