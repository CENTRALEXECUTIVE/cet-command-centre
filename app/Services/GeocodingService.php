<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Address → [lat, lng] via Google Geocoding. A tiny shared helper so the driver
 * flow (verifying a driver is actually AT a via stop) and any other caller use
 * the same logic. Returns null when there's no key, a blank/unknown address, or
 * the call fails — callers treat null as "can't verify" and fall back gracefully.
 */
class GeocodingService
{
    /** @return array{0: float, 1: float}|null */
    public function coords(?string $address): ?array
    {
        $key = Setting::mapsKey();
        if (blank($address) || Str::lower(trim($address)) === 'unknown' || ! $key) {
            return null;
        }

        try {
            $response = Http::timeout(8)->get('https://maps.googleapis.com/maps/api/geocode/json', [
                'address' => $address,
                'region' => 'gb',
                'key' => $key,
            ]);
            $loc = $response->json('results.0.geometry.location');

            return isset($loc['lat'], $loc['lng']) ? [(float) $loc['lat'], (float) $loc['lng']] : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
