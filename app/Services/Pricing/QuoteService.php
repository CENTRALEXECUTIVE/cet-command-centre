<?php

namespace App\Services\Pricing;

use App\Models\VehicleType;

/**
 * Turns pickup + destination + vehicle into a fare, the CET way:
 *   - A route in the fixed-price matrix (airports, ports, London — from the ETO
 *     screenshots) → the FIXED price, honouring the pickup zone (S20/Chesterfield
 *     differ from Sheffield/Rotherham/Barnsley).
 *   - Anything else ("free roam") → distance-based from FreeRoamPricer.
 *   - Rolls Royce (Luxury) → always price-on-request.
 *
 * Fixed prices are "both ways", so the matrix applies whether the special
 * destination is the pickup or the drop-off; the zone is taken from the other end.
 */
class QuoteService
{
    public function __construct(
        private readonly DistanceService $distance,
        private readonly FreeRoamPricer $freeRoam,
    ) {}

    /**
     * Destination detection: canonical key => alias phrases. Airports/ports are
     * matched before the generic "london" so "London Heathrow" reads as Heathrow.
     *
     * @var array<string, list<string>>
     */
    private const DEST_ALIASES = [
        'liverpool' => ['liverpool john lennon', 'liverpool airport', 'port of liverpool'],
        'humberside' => ['humberside'],
        'birmingham' => ['birmingham airport', 'birmingham international'],
        'east-midlands' => ['east midlands airport', 'east midlands', '(ema)'],
        'south-ports' => ['bournemouth', 'southampton', 'portsmouth'],
        'exeter' => ['exeter'],
        'bristol' => ['bristol'],
        'glasgow' => ['glasgow'],
        'gatwick' => ['gatwick'],
        'southend' => ['southend'],
        'stansted' => ['stansted'],
        'luton' => ['luton'],
        'newcastle' => ['newcastle'],
        'heathrow' => ['heathrow'],
        'leeds-bradford' => ['leeds bradford'],
        'manchester' => ['manchester airport'],
        'central-london' => ['central london', 'london'], // lowest priority (last)
    ];

    /**
     * Fixed-price rules from the ETO matrix. Each: destination keys, the pickup
     * zones it applies to, and the price per vehicle slug (Estate/Executive/
     * 8-Seater/8-Seater-XL/V-Class). Rolls Royce omitted = on request.
     *
     * These are the NEW rates after VAT registration: every fixed fare was raised
     * by £10 to cover the VAT the company now hands over (private customers pay
     * this VAT-inclusive price; a business that needs a VAT invoice has 20% added
     * on top). The Estate figures are Executive + £10 (also derived at runtime in
     * fixedPrice(), so the two can never drift). Free-roam fares were NOT raised.
     *
     * @var list<array{dests: list<string>, zones: list<string>, prices: array<string, float>}>
     */
    private const RULES = [
        ['dests' => ['liverpool'], 'zones' => ['s20'], 'prices' => ['estate' => 175, 'executive' => 165, 'minibus-8' => 205, 'minibus-8-xl' => 220, 'v-class' => 230]],
        ['dests' => ['liverpool', 'birmingham'], 'zones' => ['sheffield', 'rotherham'], 'prices' => ['estate' => 170, 'executive' => 160, 'minibus-8' => 200, 'minibus-8-xl' => 215, 'v-class' => 225]],
        ['dests' => ['birmingham'], 'zones' => ['s20', 'chesterfield'], 'prices' => ['estate' => 160, 'executive' => 150, 'minibus-8' => 190, 'minibus-8-xl' => 205, 'v-class' => 215]],
        // East Midlands from S20/Chesterfield is its own (cheaper) row.
        ['dests' => ['east-midlands'], 'zones' => ['s20', 'chesterfield'], 'prices' => ['estate' => 110, 'executive' => 100, 'minibus-8' => 140, 'minibus-8-xl' => 155, 'v-class' => 165]],
        ['dests' => ['humberside'], 'zones' => ['sheffield', 'rotherham', 'barnsley'], 'prices' => ['estate' => 130, 'executive' => 120, 'minibus-8' => 160, 'minibus-8-xl' => 175, 'v-class' => 185]],
        ['dests' => ['south-ports'], 'zones' => ['chesterfield', 'sheffield', 'rotherham', 'barnsley'], 'prices' => ['estate' => 420, 'executive' => 410, 'minibus-8' => 510, 'minibus-8-xl' => 525, 'v-class' => 535]],
        ['dests' => ['exeter'], 'zones' => ['chesterfield', 'sheffield', 'rotherham', 'barnsley'], 'prices' => ['estate' => 450, 'executive' => 440, 'minibus-8' => 520, 'minibus-8-xl' => 535, 'v-class' => 545]],
        ['dests' => ['bristol'], 'zones' => ['chesterfield', 'sheffield', 'rotherham', 'barnsley'], 'prices' => ['estate' => 350, 'executive' => 340, 'minibus-8' => 440, 'minibus-8-xl' => 455, 'v-class' => 465]],
        ['dests' => ['glasgow'], 'zones' => ['chesterfield', 'sheffield', 'rotherham', 'barnsley'], 'prices' => ['estate' => 470, 'executive' => 460, 'minibus-8' => 540, 'minibus-8-xl' => 555, 'v-class' => 565]],
        ['dests' => ['central-london'], 'zones' => ['sheffield', 'rotherham', 'barnsley'], 'prices' => ['estate' => 320, 'executive' => 310, 'minibus-8' => 360, 'minibus-8-xl' => 375, 'v-class' => 460]],
        ['dests' => ['gatwick', 'southend'], 'zones' => ['sheffield', 'rotherham', 'barnsley'], 'prices' => ['estate' => 370, 'executive' => 360, 'minibus-8' => 430, 'minibus-8-xl' => 445, 'v-class' => 560]],
        ['dests' => ['stansted'], 'zones' => ['sheffield', 'rotherham', 'barnsley'], 'prices' => ['estate' => 300, 'executive' => 290, 'minibus-8' => 340, 'minibus-8-xl' => 355, 'v-class' => 460]],
        ['dests' => ['newcastle', 'luton'], 'zones' => ['sheffield', 'rotherham', 'barnsley'], 'prices' => ['estate' => 270, 'executive' => 260, 'minibus-8' => 300, 'minibus-8-xl' => 320, 'v-class' => 410]],
        ['dests' => ['heathrow'], 'zones' => ['sheffield', 'rotherham', 'barnsley'], 'prices' => ['estate' => 310, 'executive' => 300, 'minibus-8' => 350, 'minibus-8-xl' => 370, 'v-class' => 460]],
        ['dests' => ['leeds-bradford', 'east-midlands', 'manchester'], 'zones' => ['sheffield', 'rotherham', 'barnsley'], 'prices' => ['estate' => 120, 'executive' => 110, 'minibus-8' => 150, 'minibus-8-xl' => 165, 'v-class' => 175]],
    ];

    /**
     * @return array{price: float|null, basis: string, miles: float|null, fixed: bool}
     */
    public function quote(string $pickup, string $destination, VehicleType $vehicleType): array
    {
        $slug = (string) $vehicleType->slug;

        // Which end is the special destination, and which is the local (zoned) end.
        [$destKey, $localText] = $this->detectDestination($pickup, $destination);
        if ($destKey !== null) {
            $price = $this->fixedPrice($destKey, $this->zonesFor($localText), $slug);
            if ($price !== null) {
                return ['price' => $price, 'basis' => 'Fixed price', 'miles' => null, 'fixed' => true];
            }
        }

        // Free roam → distance-based.
        if (! $this->freeRoam->hasRate($slug)) {
            return ['price' => null, 'basis' => 'Price on request', 'miles' => null, 'fixed' => false];
        }
        $d = $this->distance->resolve($pickup, $destination);
        $price = $this->freeRoam->price($slug, $d['miles']);

        return [
            'price' => $price,
            'basis' => 'Free roam · '.$d['miles'].' miles'.($d['source'] === 'estimate' ? ' (est.)' : ''),
            'miles' => $d['miles'],
            'fixed' => false,
        ];
    }

    /** The fixed price for a destination, preferring the most specific pickup zone. */
    private function fixedPrice(string $destKey, array $zones, string $slug): ?float
    {
        // Estate is always Executive + uplift (default £10) — derived, never the
        // stored figure, so it can't drift (see FixedPriceService for the same rule).
        if ($slug === 'estate') {
            $executive = $this->fixedPrice($destKey, $zones, 'executive');

            return $executive !== null
                ? round($executive + (float) config('cet.estate_over_executive', 10), 2)
                : null;
        }

        foreach ($zones as $zone) { // most specific first (e.g. s20 before sheffield)
            foreach (self::RULES as $rule) {
                if (in_array($destKey, $rule['dests'], true) && in_array($zone, $rule['zones'], true)) {
                    return isset($rule['prices'][$slug]) ? (float) $rule['prices'][$slug] : null;
                }
            }
        }

        return null;
    }

    /**
     * Find the special destination in either end; returns [destKey, otherEndText].
     *
     * @return array{0: string|null, 1: string}
     */
    private function detectDestination(string $pickup, string $destination): array
    {
        $pl = strtolower($pickup);
        $dl = strtolower($destination);
        foreach (self::DEST_ALIASES as $key => $aliases) {
            foreach ($aliases as $alias) {
                if (str_contains($dl, $alias)) {
                    return [$key, $pickup]; // destination is the drop-off → zone from pickup
                }
                if (str_contains($pl, $alias)) {
                    return [$key, $destination]; // destination is the pickup → zone from drop-off
                }
            }
        }

        return [null, $destination];
    }

    /** Pickup zone tokens (specific → general) from a postcode or town name. */
    private function zonesFor(string $text): array
    {
        $outward = $this->outward($text);
        if ($outward !== null) {
            if (str_starts_with($outward, 'S20')) {
                return ['s20', 'sheffield'];
            }
            if (preg_match('/^S4\d$/', $outward)) {
                return ['chesterfield'];
            }
            if (preg_match('/^S6[0-6]$/', $outward)) {
                return ['rotherham'];
            }
            if (preg_match('/^S7[0-5]$/', $outward)) {
                return ['barnsley'];
            }
            if (str_starts_with($outward, 'S')) {
                return ['sheffield'];
            }
        }

        // Fall back to a town name in the text.
        $t = strtolower($text);
        return match (true) {
            str_contains($t, 'chesterfield') => ['chesterfield'],
            str_contains($t, 'rotherham') => ['rotherham'],
            str_contains($t, 'barnsley') => ['barnsley'],
            str_contains($t, 'sheffield') => ['sheffield'],
            default => ['sheffield'], // sensible default for the home catchment
        };
    }

    /** The outward code (e.g. "S20") from an address, if present. */
    private function outward(string $text): ?string
    {
        if (preg_match('/\b([A-Z]{1,2}\d{1,2}[A-Z]?)\s*\d[A-Z]{2}\b/i', $text, $m)) {
            return strtoupper($m[1]);
        }
        if (preg_match('/\b(S\d{1,2}[A-Z]?)\b/i', $text, $m)) {
            return strtoupper($m[1]);
        }

        return null;
    }
}
