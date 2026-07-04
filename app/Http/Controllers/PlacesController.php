<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Address autocomplete for the booking/quote forms, proxied through the server
 * so the Google key stays backend-only (never shipped to the browser). Uses the
 * Google Places API (New); returns UK address suggestions as plain strings.
 */
class PlacesController extends Controller
{
    public function autocomplete(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));
        $key = Setting::mapsKey();

        if (mb_strlen($query) < 3 || ! $key) {
            return response()->json(['suggestions' => []]);
        }

        try {
            $response = Http::timeout(8)
                ->withHeaders(['X-Goog-Api-Key' => $key])
                ->post('https://places.googleapis.com/v1/places:autocomplete', [
                    'input' => $query,
                    'includedRegionCodes' => ['gb'],
                ]);

            $suggestions = collect($response->json('suggestions', []))
                ->map(fn ($s) => $s['placePrediction']['text']['text'] ?? null)
                ->filter()
                ->values()
                ->all();

            return response()->json(['suggestions' => $suggestions]);
        } catch (\Throwable) {
            return response()->json(['suggestions' => []]);
        }
    }
}
