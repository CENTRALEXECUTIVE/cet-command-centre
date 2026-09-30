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
     * Fixed-price rules mirrored 1:1 from the live ETO "Fixed Prices" matrix. Each
     * row: destination keys, the pickup zones it applies to, and the price per
     * vehicle slug — executive, 8-Seater (minibus-8), 8-Seater XL (minibus-8-xl)
     * and Executive 8-Seater (v-class, the Mercedes V-Class). Rolls Royce / Luxury
     * omitted = on request.
     *
     * These prices are the customer-facing (VAT-inclusive) standard fares, exactly
     * as ETO quotes them. Estate is NOT stored — it is derived as Executive + £10
     * at runtime in fixedPrice() (config cet.estate_over_executive), so the two can
     * never drift. A business that needs a VAT invoice has 20% added on top by the
     * widget; free-roam fares are handled separately by FreeRoamPricer.
     *
     * @var list<array{dests: list<string>, zones: list<string>, prices: array<string, float>}>
     */
    private const RULES = [
        // ---- Manchester Airport ----
        ['dests' => ['manchester'], 'zones' => ['sheffield', 'rotherham', 'barnsley'], 'prices' => ['executive' => 105, 'minibus-8' => 140, 'minibus-8-xl' => 155, 'v-class' => 165]],
        ['dests' => ['manchester'], 'zones' => ['chesterfield'], 'prices' => ['executive' => 110, 'minibus-8' => 150, 'minibus-8-xl' => 170, 'v-class' => 175]],
        ['dests' => ['manchester'], 'zones' => ['doncaster'], 'prices' => ['executive' => 140, 'minibus-8' => 185, 'minibus-8-xl' => 200, 'v-class' => 215]],
        ['dests' => ['manchester'], 'zones' => ['worksop'], 'prices' => ['executive' => 125, 'minibus-8' => 160, 'minibus-8-xl' => 180, 'v-class' => 200]],
        ['dests' => ['manchester'], 'zones' => ['mansfield', 'matlock'], 'prices' => ['executive' => 150, 'minibus-8' => 190, 'minibus-8-xl' => 205, 'v-class' => 215]],

        // ---- Leeds Bradford Airport ----
        ['dests' => ['leeds-bradford'], 'zones' => ['sheffield', 'rotherham', 'barnsley'], 'prices' => ['executive' => 105, 'minibus-8' => 140, 'minibus-8-xl' => 155, 'v-class' => 165]],
        ['dests' => ['leeds-bradford'], 'zones' => ['s20'], 'prices' => ['executive' => 105, 'minibus-8' => 145, 'minibus-8-xl' => 160, 'v-class' => 170]],
        ['dests' => ['leeds-bradford'], 'zones' => ['chesterfield'], 'prices' => ['executive' => 115, 'minibus-8' => 155, 'minibus-8-xl' => 160, 'v-class' => 170]],
        ['dests' => ['leeds-bradford'], 'zones' => ['doncaster'], 'prices' => ['executive' => 140, 'minibus-8' => 185, 'minibus-8-xl' => 200, 'v-class' => 215]],
        ['dests' => ['leeds-bradford'], 'zones' => ['mansfield'], 'prices' => ['executive' => 140, 'minibus-8' => 170, 'minibus-8-xl' => 190, 'v-class' => 200]],
        ['dests' => ['leeds-bradford'], 'zones' => ['matlock'], 'prices' => ['executive' => 155, 'minibus-8' => 175, 'minibus-8-xl' => 195, 'v-class' => 205]],
        ['dests' => ['leeds-bradford'], 'zones' => ['worksop'], 'prices' => ['executive' => 110, 'minibus-8' => 150, 'minibus-8-xl' => 165, 'v-class' => 170]],

        // ---- East Midlands Airport ---- (S20/Chesterfield are closer = cheaper)
        ['dests' => ['east-midlands'], 'zones' => ['s20', 'chesterfield'], 'prices' => ['executive' => 90, 'minibus-8' => 140, 'minibus-8-xl' => 150, 'v-class' => 155]],
        ['dests' => ['east-midlands'], 'zones' => ['sheffield', 'rotherham', 'barnsley'], 'prices' => ['executive' => 105, 'minibus-8' => 140, 'minibus-8-xl' => 155, 'v-class' => 165]],
        ['dests' => ['east-midlands'], 'zones' => ['doncaster'], 'prices' => ['executive' => 120, 'minibus-8' => 150, 'minibus-8-xl' => 170, 'v-class' => 180]],
        ['dests' => ['east-midlands'], 'zones' => ['worksop', 'mansfield', 'matlock'], 'prices' => ['executive' => 100, 'minibus-8' => 130, 'minibus-8-xl' => 150, 'v-class' => 160]],

        // ---- Birmingham Airport ----
        ['dests' => ['birmingham'], 'zones' => ['s20', 'chesterfield'], 'prices' => ['executive' => 140, 'minibus-8' => 180, 'minibus-8-xl' => 195, 'v-class' => 205]],
        ['dests' => ['birmingham'], 'zones' => ['sheffield', 'rotherham'], 'prices' => ['executive' => 150, 'minibus-8' => 190, 'minibus-8-xl' => 205, 'v-class' => 215]],
        ['dests' => ['birmingham'], 'zones' => ['barnsley'], 'prices' => ['executive' => 165, 'minibus-8' => 190, 'minibus-8-xl' => 210, 'v-class' => 220]],
        ['dests' => ['birmingham'], 'zones' => ['mansfield', 'matlock'], 'prices' => ['executive' => 145, 'minibus-8' => 185, 'minibus-8-xl' => 200, 'v-class' => 220]],
        ['dests' => ['birmingham'], 'zones' => ['worksop'], 'prices' => ['executive' => 145, 'minibus-8' => 180, 'minibus-8-xl' => 200, 'v-class' => 220]],
        ['dests' => ['birmingham'], 'zones' => ['doncaster'], 'prices' => ['executive' => 185, 'minibus-8' => 200, 'minibus-8-xl' => 220, 'v-class' => 240]],

        // ---- Heathrow ----
        ['dests' => ['heathrow'], 'zones' => ['sheffield', 'rotherham', 'barnsley'], 'prices' => ['executive' => 290, 'minibus-8' => 340, 'minibus-8-xl' => 360, 'v-class' => 450]],
        ['dests' => ['heathrow'], 'zones' => ['chesterfield'], 'prices' => ['executive' => 285, 'minibus-8' => 330, 'minibus-8-xl' => 340, 'v-class' => 420]],
        ['dests' => ['heathrow'], 'zones' => ['doncaster'], 'prices' => ['executive' => 320, 'minibus-8' => 370, 'minibus-8-xl' => 390, 'v-class' => 450]],
        ['dests' => ['heathrow'], 'zones' => ['worksop'], 'prices' => ['executive' => 320, 'minibus-8' => 385, 'minibus-8-xl' => 400, 'v-class' => 460]],
        ['dests' => ['heathrow'], 'zones' => ['mansfield', 'matlock'], 'prices' => ['executive' => 285, 'minibus-8' => 335, 'minibus-8-xl' => 355, 'v-class' => 450]],

        // ---- Gatwick / Southend ----
        ['dests' => ['gatwick', 'southend'], 'zones' => ['sheffield', 'rotherham', 'barnsley'], 'prices' => ['executive' => 350, 'minibus-8' => 420, 'minibus-8-xl' => 435, 'v-class' => 550]],
        ['dests' => ['gatwick', 'southend'], 'zones' => ['chesterfield', 'worksop'], 'prices' => ['executive' => 340, 'minibus-8' => 410, 'minibus-8-xl' => 430, 'v-class' => 540]],
        ['dests' => ['gatwick', 'southend'], 'zones' => ['mansfield', 'matlock'], 'prices' => ['executive' => 360, 'minibus-8' => 430, 'minibus-8-xl' => 450, 'v-class' => 560]],
        ['dests' => ['gatwick', 'southend'], 'zones' => ['doncaster'], 'prices' => ['executive' => 365, 'minibus-8' => 420, 'minibus-8-xl' => 440, 'v-class' => 550]],

        // ---- Stansted ----
        ['dests' => ['stansted'], 'zones' => ['sheffield', 'rotherham', 'barnsley'], 'prices' => ['executive' => 280, 'minibus-8' => 330, 'minibus-8-xl' => 345, 'v-class' => 450]],
        ['dests' => ['stansted'], 'zones' => ['chesterfield'], 'prices' => ['executive' => 295, 'minibus-8' => 330, 'minibus-8-xl' => 340, 'v-class' => 440]],
        ['dests' => ['stansted'], 'zones' => ['mansfield'], 'prices' => ['executive' => 280, 'minibus-8' => 330, 'minibus-8-xl' => 350, 'v-class' => 450]],
        ['dests' => ['stansted'], 'zones' => ['worksop', 'matlock'], 'prices' => ['executive' => 320, 'minibus-8' => 380, 'minibus-8-xl' => 395, 'v-class' => 460]],
        ['dests' => ['stansted'], 'zones' => ['doncaster'], 'prices' => ['executive' => 320, 'minibus-8' => 370, 'minibus-8-xl' => 385, 'v-class' => 450]],

        // ---- Luton / Newcastle (Airport & Port of Newcastle) ----
        ['dests' => ['luton', 'newcastle'], 'zones' => ['sheffield', 'rotherham', 'barnsley', 'mansfield'], 'prices' => ['executive' => 255, 'minibus-8' => 295, 'minibus-8-xl' => 315, 'v-class' => 405]],
        ['dests' => ['luton', 'newcastle'], 'zones' => ['matlock', 'worksop'], 'prices' => ['executive' => 240, 'minibus-8' => 290, 'minibus-8-xl' => 310, 'v-class' => 390]],
        ['dests' => ['luton', 'newcastle'], 'zones' => ['chesterfield'], 'prices' => ['executive' => 250, 'minibus-8' => 290, 'minibus-8-xl' => 305, 'v-class' => 390]],
        ['dests' => ['luton'], 'zones' => ['doncaster'], 'prices' => ['executive' => 280, 'minibus-8' => 300, 'minibus-8-xl' => 330, 'v-class' => 420]],

        // ---- Humberside ----
        ['dests' => ['humberside'], 'zones' => ['sheffield', 'rotherham', 'barnsley'], 'prices' => ['executive' => 110, 'minibus-8' => 150, 'minibus-8-xl' => 165, 'v-class' => 175]],
        ['dests' => ['humberside'], 'zones' => ['chesterfield'], 'prices' => ['executive' => 145, 'minibus-8' => 170, 'minibus-8-xl' => 180, 'v-class' => 190]],
        ['dests' => ['humberside'], 'zones' => ['doncaster'], 'prices' => ['executive' => 120, 'minibus-8' => 140, 'minibus-8-xl' => 155, 'v-class' => 160]],

        // ---- Liverpool (Airport & Port) ----
        ['dests' => ['liverpool'], 'zones' => ['sheffield', 'rotherham'], 'prices' => ['executive' => 150, 'minibus-8' => 190, 'minibus-8-xl' => 205, 'v-class' => 215]],
        ['dests' => ['liverpool'], 'zones' => ['barnsley'], 'prices' => ['executive' => 165, 'minibus-8' => 190, 'minibus-8-xl' => 210, 'v-class' => 220]],
        ['dests' => ['liverpool'], 'zones' => ['s20'], 'prices' => ['executive' => 155, 'minibus-8' => 195, 'minibus-8-xl' => 210, 'v-class' => 220]],
        ['dests' => ['liverpool'], 'zones' => ['chesterfield'], 'prices' => ['executive' => 165, 'minibus-8' => 210, 'minibus-8-xl' => 215, 'v-class' => 240]],
        ['dests' => ['liverpool'], 'zones' => ['mansfield', 'matlock'], 'prices' => ['executive' => 190, 'minibus-8' => 260, 'minibus-8-xl' => 280, 'v-class' => 290]],
        ['dests' => ['liverpool'], 'zones' => ['worksop'], 'prices' => ['executive' => 190, 'minibus-8' => 250, 'minibus-8-xl' => 270, 'v-class' => 280]],
        ['dests' => ['liverpool'], 'zones' => ['doncaster'], 'prices' => ['executive' => 210, 'minibus-8' => 260, 'minibus-8-xl' => 275, 'v-class' => 295]],

        // ---- Central London ----
        ['dests' => ['central-london'], 'zones' => ['sheffield', 'rotherham', 'barnsley'], 'prices' => ['executive' => 300, 'minibus-8' => 350, 'minibus-8-xl' => 365, 'v-class' => 450]],
        ['dests' => ['central-london'], 'zones' => ['chesterfield', 'worksop'], 'prices' => ['executive' => 330, 'minibus-8' => 350, 'minibus-8-xl' => 360, 'v-class' => 450]],
        ['dests' => ['central-london'], 'zones' => ['mansfield', 'matlock', 'doncaster'], 'prices' => ['executive' => 330, 'minibus-8' => 370, 'minibus-8-xl' => 390, 'v-class' => 460]],

        // ---- South ports (Bournemouth / Southampton / Portsmouth) ----
        ['dests' => ['south-ports'], 'zones' => ['mansfield', 'matlock'], 'prices' => ['executive' => 410, 'minibus-8' => 465, 'minibus-8-xl' => 485, 'v-class' => 495]],
        ['dests' => ['south-ports'], 'zones' => ['worksop'], 'prices' => ['executive' => 380, 'minibus-8' => 450, 'minibus-8-xl' => 470, 'v-class' => 480]],
        ['dests' => ['south-ports'], 'zones' => ['doncaster'], 'prices' => ['executive' => 400, 'minibus-8' => 480, 'minibus-8-xl' => 500, 'v-class' => 510]],
        ['dests' => ['south-ports'], 'zones' => ['chesterfield', 'sheffield', 'rotherham', 'barnsley'], 'prices' => ['executive' => 400, 'minibus-8' => 500, 'minibus-8-xl' => 515, 'v-class' => 525]],

        // ---- Exeter ----
        ['dests' => ['exeter'], 'zones' => ['chesterfield', 'mansfield', 'matlock'], 'prices' => ['executive' => 405, 'minibus-8' => 450, 'minibus-8-xl' => 470, 'v-class' => 480]],
        ['dests' => ['exeter'], 'zones' => ['worksop'], 'prices' => ['executive' => 420, 'minibus-8' => 480, 'minibus-8-xl' => 500, 'v-class' => 510]],
        ['dests' => ['exeter'], 'zones' => ['doncaster'], 'prices' => ['executive' => 405, 'minibus-8' => 595, 'minibus-8-xl' => 615, 'v-class' => 625]],
        ['dests' => ['exeter'], 'zones' => ['sheffield', 'rotherham', 'barnsley'], 'prices' => ['executive' => 430, 'minibus-8' => 510, 'minibus-8-xl' => 525, 'v-class' => 535]],

        // ---- Bristol (Airport & Port) ----
        ['dests' => ['bristol'], 'zones' => ['mansfield', 'matlock'], 'prices' => ['executive' => 325, 'minibus-8' => 375, 'minibus-8-xl' => 395, 'v-class' => 405]],
        ['dests' => ['bristol'], 'zones' => ['worksop', 'doncaster'], 'prices' => ['executive' => 320, 'minibus-8' => 365, 'minibus-8-xl' => 385, 'v-class' => 395]],
        ['dests' => ['bristol'], 'zones' => ['chesterfield', 'sheffield', 'rotherham', 'barnsley'], 'prices' => ['executive' => 330, 'minibus-8' => 430, 'minibus-8-xl' => 445, 'v-class' => 455]],

        // ---- Glasgow ----
        ['dests' => ['glasgow'], 'zones' => ['mansfield', 'matlock'], 'prices' => ['executive' => 515, 'minibus-8' => 565, 'minibus-8-xl' => 585, 'v-class' => 595]],
        ['dests' => ['glasgow'], 'zones' => ['worksop', 'chesterfield'], 'prices' => ['executive' => 500, 'minibus-8' => 550, 'minibus-8-xl' => 570, 'v-class' => 580]],
        ['dests' => ['glasgow'], 'zones' => ['doncaster'], 'prices' => ['executive' => 490, 'minibus-8' => 540, 'minibus-8-xl' => 560, 'v-class' => 570]],
        ['dests' => ['glasgow'], 'zones' => ['sheffield', 'rotherham', 'barnsley'], 'prices' => ['executive' => 450, 'minibus-8' => 530, 'minibus-8-xl' => 545, 'v-class' => 555]],
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
            // Customer-facing label — no internal "free roam" jargon; just the distance.
            'basis' => $d['miles'].' miles'.($d['source'] === 'estimate' ? ' (est.)' : ''),
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
            if (preg_match('/^S8[01]$/', $outward)) {   // Worksop (S80/S81)
                return ['worksop'];
            }
            if (str_starts_with($outward, 'DN')) {       // Doncaster
                return ['doncaster'];
            }
            if (preg_match('/^NG(1[89]|2[01])$/', $outward)) { // Mansfield (NG18–21)
                return ['mansfield'];
            }
            if (str_starts_with($outward, 'DE4')) {      // Matlock (DE4)
                return ['matlock'];
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
            str_contains($t, 'worksop') => ['worksop'],
            str_contains($t, 'doncaster') => ['doncaster'],
            str_contains($t, 'mansfield') => ['mansfield'],
            str_contains($t, 'matlock') => ['matlock'],
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
