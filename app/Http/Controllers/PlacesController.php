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
            $suggestions = $this->fetch($key, $query, $wantAddresses ? self::ADDRESS_TYPES : []);

            // Never let the address filter leave the box empty: fall back to an
            // unrestricted search if the precise-address pass found nothing.
            if (empty($suggestions) && $wantAddresses) {
                $suggestions = $this->fetch($key, $query, []);
            }

            return response()->json(['suggestions' => $suggestions]);
        } catch (\Throwable) {
            return response()->json(['suggestions' => []]);
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
     * customer picks an address we look it up with Places Text Search (part of the
     * same Places API New) and pull the postal_code component out. Empty on any
     * failure so the postcode box just stays manual.
     */
    public function resolve(Request $request): JsonResponse
    {
        $address = trim((string) $request->query('address', ''));
        $key = Setting::mapsKey();

        if (mb_strlen($address) < 4 || ! $key) {
            return response()->json(['postcode' => '', 'formatted' => '']);
        }

        try {
            $response = Http::timeout(8)
                ->withHeaders([
                    'X-Goog-Api-Key' => $key,
                    'X-Goog-FieldMask' => 'places.formattedAddress,places.addressComponents',
                ])
                ->post('https://places.googleapis.com/v1/places:searchText', [
                    'textQuery' => $address,
                    'regionCode' => 'GB',
                    'maxResultCount' => 1,
                ]);

            $place = $response->json('places.0');
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
            ]);
        } catch (\Throwable) {
            return response()->json(['postcode' => '', 'formatted' => '']);
        }
    }

    /** One call to Google Places autocomplete; returns the suggestion strings. */
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
            ->map(fn ($s) => $s['placePrediction']['text']['text'] ?? null)
            ->filter()
            ->values()
            ->all();
    }
}
