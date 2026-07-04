<?php

namespace App\Services\Pricing;

use App\Models\VehicleType;

/**
 * Turns a pickup + destination + vehicle into a fare, the CET way:
 *   - Airport run → the FIXED price for that airport (from the Sheffield-area
 *     matrix), per vehicle.
 *   - Anywhere else ("free roam") → distance-based fare from FreeRoamPricer,
 *     using the Google Routes distance.
 * Rolls Royce is always price-on-request.
 */
class QuoteService
{
    public function __construct(
        private readonly DistanceService $distance,
        private readonly FreeRoamPricer $freeRoam,
    ) {}

    /**
     * Fixed airport prices (Sheffield / Rotherham / Barnsley catchment) per
     * vehicle slug. Estate = Executive + £5; Rolls Royce = on request.
     *
     * @var array<string, array<string, float>>
     */
    private const AIRPORT_FIXED = [
        'MAN' => ['executive' => 100, 'estate' => 105, 'minibus-8' => 140, 'minibus-8-xl' => 155, 'v-class' => 165],
        'LBA' => ['executive' => 100, 'estate' => 105, 'minibus-8' => 140, 'minibus-8-xl' => 155, 'v-class' => 165],
        'EMA' => ['executive' => 100, 'estate' => 105, 'minibus-8' => 140, 'minibus-8-xl' => 155, 'v-class' => 165],
        'HUY' => ['executive' => 110, 'estate' => 115, 'minibus-8' => 150, 'minibus-8-xl' => 165, 'v-class' => 175],
        'BHX' => ['executive' => 150, 'estate' => 155, 'minibus-8' => 190, 'minibus-8-xl' => 205, 'v-class' => 215],
        'LPL' => ['executive' => 150, 'estate' => 155, 'minibus-8' => 190, 'minibus-8-xl' => 205, 'v-class' => 215],
        'LHR' => ['executive' => 290, 'estate' => 295, 'minibus-8' => 340, 'minibus-8-xl' => 360, 'v-class' => 450],
        'LGW' => ['executive' => 350, 'estate' => 355, 'minibus-8' => 420, 'minibus-8-xl' => 435, 'v-class' => 550],
        'STN' => ['executive' => 280, 'estate' => 285, 'minibus-8' => 330, 'minibus-8-xl' => 345, 'v-class' => 450],
        'LTN' => ['executive' => 250, 'estate' => 255, 'minibus-8' => 290, 'minibus-8-xl' => 310, 'v-class' => 400],
    ];

    /** @var array<string, list<string>> airport code => name/postcode aliases. */
    private const AIRPORT_ALIASES = [
        'MAN' => ['manchester airport', 'm90'],
        'LBA' => ['leeds bradford', 'ls19'],
        'EMA' => ['east midlands airport', 'nottingham east midlands', 'de74'],
        'HUY' => ['humberside airport', 'dn39'],
        'BHX' => ['birmingham airport', 'b26'],
        'LPL' => ['liverpool john lennon', 'liverpool airport', 'l24'],
        'LHR' => ['heathrow', 'tw6'],
        'LGW' => ['gatwick', 'rh6'],
        'STN' => ['stansted', 'cm24'],
        'LTN' => ['luton airport', 'london luton', 'lu2'],
    ];

    /**
     * @return array{price: float|null, basis: string, miles: float|null, fixed: bool}
     */
    public function quote(string $pickup, string $destination, VehicleType $vehicleType): array
    {
        $slug = (string) $vehicleType->slug;

        // Airport run → fixed price.
        $code = $this->detectAirport($pickup.' '.$destination);
        if ($code !== null && isset(self::AIRPORT_FIXED[$code][$slug])) {
            return [
                'price' => (float) self::AIRPORT_FIXED[$code][$slug],
                'basis' => "Fixed price · {$code} airport",
                'miles' => null,
                'fixed' => true,
            ];
        }

        // Free roam → distance-based.
        if (! $this->freeRoam->hasRate($slug)) {
            return ['price' => null, 'basis' => 'Price on request', 'miles' => null, 'fixed' => false];
        }
        $d = $this->distance->resolve($pickup, $destination);
        $price = $this->freeRoam->price($slug, $d['miles']);

        return [
            'price' => $price,
            'basis' => 'Free roam · '.$d['miles'].' miles'.($d['source'] === 'estimate' ? ' (estimated)' : ''),
            'miles' => $d['miles'],
            'fixed' => false,
        ];
    }

    private function detectAirport(string $text): ?string
    {
        // Bracketed IATA code, e.g. "(MAN)".
        if (preg_match('/\(([A-Z]{3})\)/', $text, $m) && isset(self::AIRPORT_FIXED[$m[1]])) {
            return $m[1];
        }
        $needle = strtolower($text);
        foreach (self::AIRPORT_ALIASES as $code => $aliases) {
            foreach ($aliases as $alias) {
                if (str_contains($needle, $alias)) {
                    return $code;
                }
            }
        }

        return null;
    }
}
