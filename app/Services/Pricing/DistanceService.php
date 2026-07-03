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
            // Google's new Routes API (the legacy Distance Matrix is disabled for
            // new projects). Ask only for distance + duration via the field mask.
            $response = Http::timeout(10)
                ->withHeaders([
                    'X-Goog-Api-Key' => \App\Models\Setting::mapsKey(),
                    'X-Goog-FieldMask' => 'routes.distanceMeters,routes.duration',
                ])
                ->post('https://routes.googleapis.com/directions/v2:computeRoutes', [
                    'origin' => ['address' => $pickup],
                    'destination' => ['address' => $destination],
                    'travelMode' => 'DRIVE',
                    'units' => 'IMPERIAL',
                ]);

            $route = $response->json('routes.0');
            if ($response->successful() && ! empty($route['distanceMeters'])) {
                return [
                    'miles' => round(($route['distanceMeters'] ?? 0) / 1609.34, 1),
                    // duration comes back as e.g. "1234s".
                    'minutes' => (int) round(((int) rtrim((string) ($route['duration'] ?? '0s'), 's')) / 60),
                ];
            }

            Log::warning('Routes API returned no route', ['body' => substr($response->body(), 0, 300)]);
        } catch (\Throwable $e) {
            Log::warning('Routes API exception', ['error' => $e->getMessage()]);
        }

        return null;
    }
}
