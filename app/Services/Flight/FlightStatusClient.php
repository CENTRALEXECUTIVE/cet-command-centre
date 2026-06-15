<?php

namespace App\Services\Flight;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin client for a flight-status API (AviationStack-shaped by default).
 * Returns the live arrival status for a flight, or null when unconfigured or on
 * failure so the monitor degrades gracefully.
 */
class FlightStatusClient
{
    public function configured(): bool
    {
        return filled(config('services.flight.key'));
    }

    /**
     * @return array{status:?string, scheduled_arrival:?Carbon, estimated_arrival:?Carbon, delay_minutes:int}|null
     */
    public function arrival(string $flightNumber, ?Carbon $date = null): ?array
    {
        if (! $this->configured()) {
            return null;
        }

        try {
            $response = Http::timeout(15)->get(rtrim(config('services.flight.base_url'), '/').'/flights', [
                'access_key' => config('services.flight.key'),
                'flight_iata' => $flightNumber,
                'flight_date' => $date?->toDateString(),
            ]);

            $flight = $response->json('data.0');
            if (! $response->successful() || ! $flight) {
                return null;
            }

            $arrival = $flight['arrival'] ?? [];

            return [
                'status' => $flight['flight_status'] ?? null,
                'scheduled_arrival' => $this->parse($arrival['scheduled'] ?? null),
                'estimated_arrival' => $this->parse($arrival['estimated'] ?? $arrival['scheduled'] ?? null),
                'delay_minutes' => (int) ($arrival['delay'] ?? 0),
            ];
        } catch (\Throwable $e) {
            Log::warning('Flight status fetch failed', ['flight' => $flightNumber, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function parse(?string $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
