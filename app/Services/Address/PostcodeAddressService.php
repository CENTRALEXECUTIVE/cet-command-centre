<?php

namespace App\Services\Address;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Full "postcode → every address" lookup via getAddress.io (Royal Mail PAF).
 * This is the one thing Google can't do: list all delivery-point addresses at a
 * UK postcode so the customer picks their exact house.
 *
 * It is a silent no-op until a key is set (Settings → getaddress_key), so the
 * booking form always works — the widget falls back to Google's type-ahead.
 * Results are cached hard (addresses don't change) to protect the free tier.
 */
class PostcodeAddressService
{
    private const CACHE_TTL = 60 * 60 * 24 * 30; // 30 days

    public function enabled(): bool
    {
        return filled($this->key());
    }

    public function key(): ?string
    {
        return Setting::getAddressKey();
    }

    /**
     * Every address at a postcode, as display strings. Empty when the postcode is
     * invalid, no key is set, or the lookup fails — callers fall back to Google.
     *
     * @return array<int, string>
     */
    public function lookup(string $postcode): array
    {
        $pc = $this->normalise($postcode);
        if ($pc === '' || ! $this->enabled()) {
            return [];
        }

        return Cache::remember('paf:'.$pc, self::CACHE_TTL, function () use ($pc) {
            try {
                $response = Http::timeout(8)->get('https://api.getAddress.io/find/'.rawurlencode($pc), [
                    'api-key' => $this->key(),
                    'expand' => 'true',
                ]);

                if (! $response->successful()) {
                    return [];
                }

                $pcOut = (string) ($response->json('postcode') ?: $pc);

                return collect($response->json('addresses', []))
                    ->map(fn ($a) => $this->format($a, $pcOut))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
            } catch (\Throwable $e) {
                Log::warning('[paf] postcode lookup failed: '.$e->getMessage());

                return [];
            }
        });
    }

    /** Build a clean "12 Harney Close, Sheffield, S9 5BW" string from a result row. */
    private function format(mixed $address, string $postcode): ?string
    {
        // expand=true → object with a formatted_address array; otherwise a string.
        if (is_string($address)) {
            $parts = array_map('trim', explode(',', $address));
        } elseif (is_array($address)) {
            $parts = is_array($address['formatted_address'] ?? null)
                ? $address['formatted_address']
                : [$address['line_1'] ?? '', $address['line_2'] ?? '', $address['town_or_city'] ?? '', $address['county'] ?? ''];
        } else {
            return null;
        }

        $parts = array_values(array_filter(array_map('trim', $parts), fn ($p) => $p !== ''));
        if (! $parts) {
            return null;
        }

        if ($postcode !== '' && ! in_array(strtoupper($postcode), array_map('strtoupper', $parts), true)) {
            $parts[] = strtoupper($postcode);
        }

        return implode(', ', $parts);
    }

    /** Normalise to canonical "AREA UNIT" form; empty if not a valid UK postcode. */
    public function normalise(string $postcode): string
    {
        $pc = strtoupper(preg_replace('/\s+/', '', $postcode));
        if (! preg_match('/^[A-Z]{1,2}[0-9][A-Z0-9]?[0-9][A-Z]{2}$/', $pc)) {
            return '';
        }

        return substr($pc, 0, -3).' '.substr($pc, -3);
    }
}
