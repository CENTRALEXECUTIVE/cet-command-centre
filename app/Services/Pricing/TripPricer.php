<?php

namespace App\Services\Pricing;

use App\Models\VehicleType;
use App\Models\Voucher;
use App\Services\Payments\VatService;
use App\Support\AirportMatcher;
use Illuminate\Support\Carbon;

/**
 * The WHOLE-TRIP price for a journey, computed the same way for the customer widget
 * as for the admin form, so the two can never drift (the Nov 2026 parity sweep found
 * the widget copying the outbound onto the return, a different ribbon rate, extras
 * once per trip, the voucher on one leg only, and surcharges on one side only).
 *
 *   - Each leg via FareCalculator on its OWN route, OWN drag (base → that leg's
 *     pickup) and OWN pickup time (festive + office surcharges on the standard fare).
 *   - Extras (seats, stops, ribbons, wheelchair) on EACH leg; meet & greet only on a
 *     leg collected at an airport (same rule as the admin strip).
 *   - A voucher comes off the WHOLE trip; the discounted total is split across the
 *     legs in the quote's ratio — exactly BookingService::splitReturnFare.
 *   - VAT (when invoiced) is added per leg exactly as Booking::amountPayable() does,
 *     so the total shown is, to the penny, what the pay link charges.
 *
 * Every amount stored is EX-VAT (net). Null figures = price on request.
 */
class TripPricer
{
    public function __construct(
        private readonly FareCalculator $fares,
        private readonly VatService $vat,
    ) {}

    /**
     * @param  array{
     *   pickup: string, destination: string, pickup_at: Carbon,
     *   journey_type?: string, return_pickup_at?: Carbon|null,
     *   return_pickup?: string|null, return_destination?: string|null,
     *   meet_greet?: bool, child_seats?: int, booster_seats?: int, infant_seats?: int,
     *   stopovers?: int, ribbons?: bool, wheelchair?: bool, vat?: bool, voucher?: string|null
     * }  $in
     * @return array{
     *   poa: bool, out: array, ret: array|null, net: float|null, discount: float,
     *   voucher_valid: bool, legs_net: array{0: float|null, 1: float|null},
     *   legs_payable: array{0: float|null, 1: float|null}, vat: float, total: float|null
     * }
     */
    public function price(VehicleType $vehicle, array $in): array
    {
        $isReturn = ($in['journey_type'] ?? 'one_way') === 'return';
        $options = [
            'stopovers' => (int) ($in['stopovers'] ?? 0),
            'child_seats' => (int) ($in['child_seats'] ?? 0),
            'booster_seats' => (int) ($in['booster_seats'] ?? 0),
            'infant_seats' => (int) ($in['infant_seats'] ?? 0),
            'ribbons' => (bool) ($in['ribbons'] ?? false),
            'wheelchair' => (bool) ($in['wheelchair'] ?? false),
        ];
        $mg = (bool) ($in['meet_greet'] ?? true);

        $outPickup = $in['pickup'];
        $outDest = $in['destination'];
        $retPickup = ! empty($in['return_pickup']) ? $in['return_pickup'] : $outDest;
        $retDest = ! empty($in['return_destination']) ? $in['return_destination'] : $outPickup;

        $out = $this->fares->calculate($outPickup, $outDest, $vehicle, $in['pickup_at'],
            $options + ['meet_greet' => $mg && AirportMatcher::isAirport($outPickup)]);
        $ret = $isReturn
            ? $this->fares->calculate($retPickup, $retDest, $vehicle, $in['return_pickup_at'] ?? $in['pickup_at'],
                $options + ['meet_greet' => $mg && AirportMatcher::isAirport($retPickup)])
            : null;

        $poa = $out['poa'] || ($ret && $ret['poa']);
        if ($poa) {
            return [
                'poa' => true, 'out' => $out, 'ret' => $ret, 'net' => null, 'discount' => 0.0,
                'voucher_valid' => false, 'legs_net' => [null, null], 'legs_payable' => [null, null],
                'vat' => 0.0, 'total' => null,
            ];
        }

        $outNet = (float) $out['subtotal'];
        $retNet = $ret ? (float) $ret['subtotal'] : 0.0;
        $net = round($outNet + $retNet, 2);

        // Voucher on the WHOLE trip (net, before VAT) — same as the admin form.
        $voucher = Voucher::findByCode($in['voucher'] ?? null);
        $valid = $voucher !== null && $voucher->isRedeemable();
        $discount = ($valid && $net > 0) ? $voucher->discountOn($net) : 0.0;
        $after = round($net - $discount, 2);

        // Split across the legs in the quote's ratio (BookingService::splitReturnFare).
        if ($ret) {
            $legOut = ($outNet + $retNet) > 0 ? round($after * ($outNet / ($outNet + $retNet)), 2) : round($after / 2, 2);
            $legRet = round($after - $legOut, 2);
        } else {
            [$legOut, $legRet] = [$after, null];
        }

        // VAT per leg, exactly as Booking::amountPayable() → VatService::fromNet().
        $vatOn = (bool) ($in['vat'] ?? false);
        $pay = fn (?float $n) => $n === null ? null : ($vatOn ? $this->vat->fromNet($n)['gross'] : $n);
        $payOut = $pay($legOut);
        $payRet = $pay($legRet);
        $total = round($payOut + ($payRet ?? 0.0), 2);

        return [
            'poa' => false,
            'out' => $out,
            'ret' => $ret,
            'net' => $net,
            'discount' => $discount,
            'voucher_valid' => $valid,
            'legs_net' => [$legOut, $legRet],
            'legs_payable' => [$payOut, $payRet],
            'vat' => round($total - $after, 2),
            'total' => $total,
        ];
    }
}
