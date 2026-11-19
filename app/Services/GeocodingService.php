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

    /** A full UK postcode in the text (e.g. "S11 9AB"), normalised, or null. */
    public static function postcodeIn(?string $text): ?string
    {
        if ($text && preg_match('/\b([A-Z]{1,2}\d[A-Z\d]?)\s*(\d[A-Z]{2})\b/i', $text, $m)) {
            return strtoupper($m[1].' '.$m[2]);
        }

        return null;
    }

    /**
     * Address → its full UK postcode via Google Geocoding (Google's autocomplete
     * text usually leaves it off, e.g. "19 High View, Sheffield, UK"). Null when
     * the address already has one we can read, there's no key, or Google only
     * knows a partial ("S2"). Cached 30 days.
     */
    public function postcode(?string $address): ?string
    {
        $key = Setting::mapsKey();
        if (blank($address) || ! $key) {
            return null;
        }
        if ($found = self::postcodeIn($address)) {
            return $found;
        }

        $cacheKey = 'geo-postcode:'.md5(Str::lower(trim($address)));
        $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached !== '' ? $cached : null;
        }
        // Don't hammer Google while it's failing (a down network must not slow every page).
        if (\Illuminate\Support\Facades\Cache::has('geo-postcode:backoff')) {
            return null;
        }
        try {
            $response = Http::timeout(5)->get('https://maps.googleapis.com/maps/api/geocode/json', [
                'address' => $address,
                'region' => 'gb',
                'key' => $key,
            ]);
            if (! $response->successful()) {
                \Illuminate\Support\Facades\Cache::put('geo-postcode:backoff', 1, now()->addMinutes(5));

                return null;
            }
            $found = '';
            foreach (($response->json('results.0.address_components') ?? []) as $c) {
                if (in_array('postal_code', $c['types'] ?? [], true)) {
                    $found = self::postcodeIn($c['long_name'] ?? '') ?? '';
                    break;
                }
            }
            // Only cache real answers (a postcode, or Google genuinely having none).
            if ($found !== '' || in_array($response->json('status'), ['OK', 'ZERO_RESULTS'], true)) {
                \Illuminate\Support\Facades\Cache::put($cacheKey, $found, now()->addDays(30));
            }

            return $found !== '' ? $found : null;
        } catch (\Throwable) {
            \Illuminate\Support\Facades\Cache::put('geo-postcode:backoff', 1, now()->addMinutes(5));

            return null;
        }
    }
}
