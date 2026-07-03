<?php

namespace App\Services\Pricing;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Resolves the distance (miles) and duration (minutes) between two addresses.
 *
 * Uses the Google Distance Matrix API when a key is configured. Operator-
 * supplied values always win (the booking form / maps widget may already know
 * them), and a conservative default is used as a last resort so a quote can
 * always be produced.
 */
class DistanceService
{
    /**
     * @return array{miles: float, minutes: int, source: string}
     */
    public function resolve(string $pickup, string $destination, ?float $miles = null, ?int $minutes = null): array
    {
        if ($miles !== null && $minutes !== null) {
            return ['miles' => round($miles, 1), 'minutes' => $minutes, 'source' => 'provided'];
        }

        if ($this->configured()) {
            $api = $this->queryGoogle($pickup, $destination);
            if ($api) {
                return $api + ['source' => 'google'];
            }
        }

        // Conservative default keeps quoting available without a maps provider.
        return ['miles' => $miles ?? 10.0, 'minutes' => $minutes ?? 25, 'source' => 'estimate'];
    }

    public function configured(): bool
    {
        return filled(\App\Models\Setting::mapsKey());
    }

    /**
     * @return array{miles: float, minutes: int}|null
     */
    private function queryGoogle(string $pickup, string $destination): ?array
    {
        try {
            $response = Http::timeout(10)->get('https://maps.googleapis.com/maps/api/distancematrix/json', [
                'origins' => $pickup,
                'destinations' => $destination,
                'units' => 'imperial',
                'key' => \App\Models\Setting::mapsKey(),
            ]);

            $element = $response->json('rows.0.elements.0');
            if ($response->successful() && ($element['status'] ?? null) === 'OK') {
                return [
                    'miles' => round(($element['distance']['value'] ?? 0) / 1609.34, 1),
                    'minutes' => (int) round(($element['duration']['value'] ?? 0) / 60),
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('Distance Matrix exception', ['error' => $e->getMessage()]);
        }

        return null;
    }
}
