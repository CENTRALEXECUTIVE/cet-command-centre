<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\Address\PostcodeAddressService;
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
    /** Precise-address place types — house/street/postcode, no businesses. */
    private const ADDRESS_TYPES = ['street_address', 'premise', 'subpremise', 'route', 'postal_code'];

    public function autocomplete(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));
        $key = Setting::mapsKey();

        // Two characters is enough for a postcode prefix (e.g. "S9") to start
        // suggesting; the type-ahead debounces so this isn't chatty.
        if (mb_strlen($query) < 2 || ! $key) {
            return response()->json(['suggestions' => []]);
        }

        // types=address biases toward precise street addresses (so typing a house
        // number lists the exact addresses to pick). Other fields (e.g. drop-off)
        // stay unrestricted so airports, hotels and stations still appear.
        $wantAddresses = $request->query('types') === 'address';

        try {
            $predictions = $this->fetch($key, $query, $wantAddresses ? self::ADDRESS_TYPES : []);

            // Never let the address filter leave the box empty: fall back to an
            // unrestricted search if the precise-address pass found nothing.
            if (empty($predictions) && $wantAddresses) {
                $predictions = $this->fetch($key, $query, []);
            }

            return response()->json([
                // Backward-compatible flat list for display, plus the place ids so
                // a chosen address can be resolved to its postcode reliably.
                'suggestions' => array_column($predictions, 'text'),
                'predictions' => $predictions,
            ]);
        } catch (\Throwable) {
            return response()->json(['suggestions' => [], 'predictions' => []]);
        }
    }

    /**
     * Full "every address at this postcode" list via getAddress.io (Royal Mail
     * PAF). Empty when no key is set or the postcode is invalid — the form then
     * falls back to Google's type-ahead. Includes `enabled` so the client knows
     * whether to keep offering Google suggestions.
     */
    public function addresses(Request $request, PostcodeAddressService $paf): JsonResponse
    {
        $postcode = trim((string) $request->query('postcode', ''));

        return response()->json([
            'enabled' => $paf->enabled(),
            'postcode' => $paf->normalise($postcode),
            'addresses' => $paf->lookup($postcode),
        ]);
    }

    /**
     * Resolve a chosen address to its POSTCODE (and canonical full address).
     * Google's autocomplete predictions usually omit the postcode, so once the
     * customer picks an address we look it up and pull the postal_code component.
     *
     * Preferred path is Place Details by the prediction's placeId (reliable, and
     * always returns addressComponents). Free-typed addresses with no placeId fall
     * back to a Text Search. Empty on any failure so the postcode box stays manual.
     */
    public function resolve(Request $request): JsonResponse
    {
        $placeId = trim((string) $request->query('place_id', ''));
        $address = trim((string) $request->query('address', ''));
        $key = Setting::mapsKey();

        if (! $key || ($placeId === '' && mb_strlen($address) < 4)) {
            return response()->json(['postcode' => '', 'formatted' => '']);
        }

        try {
            $place = $placeId !== ''
                ? $this->placeDetails($key, $placeId)
                : $this->textSearchPlace($key, $address);

            if (! $place) {
                return response()->json(['postcode' => '', 'formatted' => '']);
            }

            $postcode = '';
            foreach (($place['addressComponents'] ?? []) as $component) {
                if (in_array('postal_code', $component['types'] ?? [], true)) {
                    $postcode = $component['longText'] ?? $component['shortText'] ?? '';
                    break;
                }
            }

            return response()->json([
                'postcode' => strtoupper($postcode),
                'formatted' => $place['formattedAddress'] ?? '',
                // Is this a home/street (needs a postcode for the driver) vs a named
                // venue (airport/station/hotel — no postcode expected)?
                'residential' => $this->isResidential($place['types'] ?? []),
            ]);
        } catch (\Throwable) {
            return response()->json(['postcode' => '', 'formatted' => '']);
        }
    }

    /**
     * Reverse-geocode a lat/lng (from the browser's "use my location" button) to a
     * street address + postcode, so the customer doesn't have to type it. Uses the
     * Google Geocoding API with the server-side key. Empty on any failure.
     */
    public function reverse(Request $request): JsonResponse
    {
        $lat = $request->query('lat');
        $lng = $request->query('lng');
        $key = Setting::mapsKey();

        if (! $key || ! is_numeric($lat) || ! is_numeric($lng)) {
            return response()->json(['postcode' => '', 'formatted' => '']);
        }

        try {
            $response = Http::timeout(8)->get('https://maps.googleapis.com/maps/api/geocode/json', [
                'latlng' => ((float) $lat).','.((float) $lng),
                'region' => 'gb',
                'key' => $key,
            ]);

            $result = $response->json('results.0');
            if (! $result) {
                return response()->json(['postcode' => '', 'formatted' => '']);
            }

            $postcode = '';
            foreach (($result['address_components'] ?? []) as $component) {
                if (in_array('postal_code', $component['types'] ?? [], true)) {
                    $postcode = $component['long_name'] ?? $component['short_name'] ?? '';
                    break;
                }
            }

            return response()->json([
                'postcode' => strtoupper($postcode),
                'formatted' => $result['formatted_address'] ?? '',
            ]);
        } catch (\Throwable) {
            return response()->json(['postcode' => '', 'formatted' => '']);
        }
    }

    /** Place Details (New) for a known placeId — returns address + components. */
    private function placeDetails(string $key, string $placeId): ?array
    {
        $response = Http::timeout(8)
            ->withHeaders([
                'X-Goog-Api-Key' => $key,
                'X-Goog-FieldMask' => 'formattedAddress,addressComponents,types',
            ])
            ->get('https://places.googleapis.com/v1/places/'.rawurlencode($placeId));

        return $response->successful() ? $response->json() : null;
    }

    /**
     * A home/street address (postcode matters for the driver) vs a named venue
     * such as an airport, station or hotel (no postcode expected). Based on the
     * Google place types.
     *
     * @param  list<string>  $types
     */
    private function isResidential(array $types): bool
    {
        $venue = ['airport', 'establishment', 'point_of_interest', 'transit_station',
            'train_station', 'lodging', 'tourist_attraction', 'shopping_mall'];
        if (array_intersect($types, $venue)) {
            return false;
        }
        $home = ['premise', 'subpremise', 'street_address', 'route', 'residential'];

        return (bool) array_intersect($types, $home);
    }

    /** Text Search (New) fallback for a free-typed address (no placeId). */
    private function textSearchPlace(string $key, string $address): ?array
    {
        $response = Http::timeout(8)
            ->withHeaders([
                'X-Goog-Api-Key' => $key,
                'X-Goog-FieldMask' => 'places.formattedAddress,places.addressComponents,places.types',
            ])
            ->post('https://places.googleapis.com/v1/places:searchText', [
                'textQuery' => $address,
                'regionCode' => 'GB',
                'maxResultCount' => 1,
            ]);

        return $response->json('places.0');
    }

    /**
     * One call to Google Places autocomplete; returns [{text, placeId}] rows so a
     * chosen address can later be resolved to its postcode by place id.
     *
     * @return array<int, array{text: string, placeId: string}>
     */
    private function fetch(string $key, string $query, array $primaryTypes): array
    {
        $body = ['input' => $query, 'includedRegionCodes' => ['gb']];
        if ($primaryTypes) {
            $body['includedPrimaryTypes'] = $primaryTypes;
        }

        $response = Http::timeout(8)
            ->withHeaders(['X-Goog-Api-Key' => $key])
            ->post('https://places.googleapis.com/v1/places:autocomplete', $body);

        return collect($response->json('suggestions', []))
            ->map(fn ($s) => [
                'text' => $s['placePrediction']['text']['text'] ?? null,
                'placeId' => $s['placePrediction']['placeId'] ?? '',
            ])
            ->filter(fn ($p) => filled($p['text']))
            ->values()
            ->all();
    }
}
