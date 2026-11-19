<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Setting;
use App\Services\GeocodingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Nov 2026: a web booking's pickup "19 High View, Sheffield, UK" reached the
 * driver with no postcode, and a driver couldn't Complete at Heathrow Terminal 5
 * because "Heathrow" geocodes to one point while terminals are miles apart.
 */
class PostcodeAndAirportFenceTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGeocode(string $postcode = 'S2 3AB'): void
    {
        Setting::set('google_maps_key', 'test-key');
        config(['services.google.maps_key' => 'test-key']);
        Http::fake(['maps.googleapis.com/*' => Http::response([
            'status' => 'OK',
            'results' => [['address_components' => [['long_name' => $postcode, 'types' => ['postal_code']]]]],
        ])]);
    }

    public function test_postcode_is_found_and_cached(): void
    {
        $this->fakeGeocode();
        $geo = app(GeocodingService::class);
        $this->assertSame('S2 3AB', $geo->postcode('19 High View, Sheffield, UK'));
        $this->assertSame('S2 3AB', $geo->postcode('19 High View, Sheffield, UK'));
        Http::assertSentCount(1);
        // Already has one → no call, normalised.
        $this->assertSame('S11 9AB', $geo->postcode('1 Road, Sheffield s119ab'));
        Http::assertSentCount(1);
    }

    public function test_a_failing_google_is_not_cached_and_backs_off(): void
    {
        Setting::set('google_maps_key', 'test-key');
        config(['services.google.maps_key' => 'test-key']);
        Http::fake(['maps.googleapis.com/*' => Http::sequence()->push('', 500)->push([
            'status' => 'OK',
            'results' => [['address_components' => [['long_name' => 'S2 3AB', 'types' => ['postal_code']]]]],
        ])]);
        $geo = app(GeocodingService::class);
        $this->assertNull($geo->postcode('19 High View, Sheffield, UK'));
        $this->assertNull($geo->postcode('20 High View, Sheffield, UK'));
        Http::assertSentCount(1); // backed off, didn't hammer
        Cache::forget('geo-postcode:backoff');
        $this->assertSame('S2 3AB', $geo->postcode('19 High View, Sheffield, UK'));
    }

    public function test_driver_sees_the_postcode_but_the_booking_is_unchanged(): void
    {
        $this->fakeGeocode();
        $b = Booking::factory()->create([
            'pickup_address' => '19 High View, Sheffield, UK',
            'destination_address' => 'Manchester Airport (MAN), Manchester, UK',
        ]);
        $this->assertSame('19 High View, Sheffield, UK, S2 3AB', $b->driverAddress('pickup'));
        // Airports are left alone.
        $this->assertSame('Manchester Airport (MAN), Manchester, UK', $b->driverAddress('dropoff'));
        $this->assertSame('19 High View, Sheffield, UK', $b->fresh()->pickup_address);
    }

    public function test_airport_dropoff_gets_a_wider_fence_but_mid_route_is_still_blocked(): void
    {
        $centre = [51.4700, -0.4543]; // where "Heathrow Airport" geocodes
        $airport = Booking::factory()->create([
            'status' => BookingStatus::Collected->value,
            'destination_address' => 'Heathrow Airport (LHR), Hounslow, UK',
            'meta' => ['geo' => ['dropoff' => $centre]],
        ]);
        $home = Booking::factory()->create([
            'status' => BookingStatus::Collected->value,
            'destination_address' => '10 Some Street, Hounslow TW3 1AA',
            'meta' => ['geo' => ['dropoff' => $centre]],
        ]);

        // ~3.7 km west of the centre (Terminal 5 side): OK for the airport only.
        $t5 = [51.4720, -0.5080];
        $this->assertSame('ok', $airport->checkDriverAtDropoff($t5[0], $t5[1], 10));
        $this->assertSame('far', $home->checkDriverAtDropoff($t5[0], $t5[1], 10));

        // Still on the M25/M4 ~15 km out: blocked even for the airport.
        $this->assertSame('far', $airport->checkDriverAtDropoff(51.4900, -0.2500, 10));
    }
}
