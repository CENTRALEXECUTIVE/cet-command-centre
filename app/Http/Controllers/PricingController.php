<?php

namespace App\Http\Controllers;

use App\Models\VehicleType;
use App\Services\Pricing\FareCalculator;
use App\Services\Pricing\QuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Live fare estimate for the booking/quote forms: fixed price for airport runs,
 * distance-based for free roam.
 */
class PricingController extends Controller
{
    public function estimate(Request $request, QuoteService $quotes): JsonResponse
    {
        $data = $request->validate([
            'pickup' => ['required', 'string', 'max:300'],
            'destination' => ['required', 'string', 'max:300'],
            'vehicle_type_id' => ['required', Rule::exists('vehicle_types', 'id')],
        ]);

        $quote = $quotes->quote($data['pickup'], $data['destination'], VehicleType::find($data['vehicle_type_id']));

        return response()->json($quote);
    }

    /**
     * The admin booking form's per-vehicle price strip — the FULL fare for every
     * active vehicle, including the via-stop surcharge (£/stop) and the ticked
     * extras (meet & greet, seats, ribbons), via FareCalculator. This is what the
     * public/widget flows have always applied; the admin strip previously showed
     * the base fare only, so a job with via stops quoted with no stop fee.
     */
    public function strip(Request $request, FareCalculator $fares): JsonResponse
    {
        $data = $request->validate([
            'pickup' => ['required', 'string', 'max:300'],
            'destination' => ['required', 'string', 'max:300'],
            'pickup_at' => ['nullable', 'date'],
            'journey_type' => ['nullable', Rule::in(['one_way', 'return', 'hourly'])],
            'return_pickup_at' => ['nullable', 'date'],
            // Optional DIFFERENT return-leg addresses; blank → the return reverses.
            'return_pickup' => ['nullable', 'string', 'max:300'],
            'return_destination' => ['nullable', 'string', 'max:300'],
            'stops' => ['nullable', 'integer', 'min:0', 'max:10'],
            'meet_greet' => ['nullable', 'boolean'],
            'child_seats' => ['nullable', 'integer', 'min:0', 'max:20'],
            'booster_seats' => ['nullable', 'integer', 'min:0', 'max:20'],
            'infant_seats' => ['nullable', 'integer', 'min:0', 'max:20'],
            'ribbons' => ['nullable', 'boolean'],
        ]);

        $pickupAt = ! empty($data['pickup_at']) ? Carbon::parse($data['pickup_at']) : now();
        // A return is two real journeys: the outbound (pickup → destination) and the
        // inbound (destination → pickup), each priced by the same engine at its own
        // pickup time, then summed. No invented multiplier or discount — the office
        // can still edit the figure if it gives a return deal.
        $isReturn = ($data['journey_type'] ?? 'one_way') === 'return';
        $returnAt = $isReturn && ! empty($data['return_pickup_at'])
            ? Carbon::parse($data['return_pickup_at'])
            : $pickupAt;
        $options = [
            'stopovers' => (int) ($data['stops'] ?? 0),
            'meet_greet' => (bool) ($data['meet_greet'] ?? false),
            'child_seats' => (int) ($data['child_seats'] ?? 0),
            'booster_seats' => (int) ($data['booster_seats'] ?? 0),
            'infant_seats' => (int) ($data['infant_seats'] ?? 0),
            'ribbons' => (bool) ($data['ribbons'] ?? false),
        ];

        // On a return, meet & greet is charged only on the leg that's an AIRPORT
        // PICKUP (an arrival) — never on both. MAN → office → Ibis BHX charged it on
        // the office pickup too (£150 Birmingham fare showed as £160).
        $returnPickup = ! empty($data['return_pickup']) ? $data['return_pickup'] : $data['destination'];
        $outOptions = $options;
        $inOptions = $options;
        if ($isReturn && $options['meet_greet']) {
            $outOptions['meet_greet'] = \App\Support\AirportMatcher::isAirport($data['pickup']);
            $inOptions['meet_greet'] = \App\Support\AirportMatcher::isAirport($returnPickup);
        }

        $options = VehicleType::where('is_active', true)->orderBy('sort_order')->get()
            ->map(function (VehicleType $vt) use ($fares, $data, $pickupAt, $returnAt, $isReturn, $outOptions, $inOptions, $returnPickup) {
                $out = $fares->calculate($data['pickup'], $data['destination'], $vt, $pickupAt, $outOptions);
                // The return leg prices its OWN addresses when the office gave different
                // ones; otherwise it reverses the outbound (destination → pickup).
                $in = $isReturn
                    ? $fares->calculate(
                        $returnPickup,
                        ! empty($data['return_destination']) ? $data['return_destination'] : $data['pickup'],
                        $vt, $returnAt, $inOptions
                    )
                    : null;

                $poa = $out['poa'] || ($in && $in['poa']);
                $total = $poa
                    ? null
                    : round((float) $out['subtotal'] + ($in ? (float) $in['subtotal'] : 0), 2);

                // A human-readable breakdown so the office sees exactly what makes
                // up the fare (base + any surcharge + each priced extra), not just
                // a total — the "live fare breakdown" the admin form was missing.
                // On a return it shows the two legs so the double price is obvious.
                $lines = [];
                if ($isReturn) {
                    if ($out['subtotal'] !== null) {
                        $lines[] = ['label' => 'Outbound leg', 'amount' => (float) $out['subtotal']];
                    }
                    if ($in && $in['subtotal'] !== null) {
                        $lines[] = ['label' => 'Return leg', 'amount' => (float) $in['subtotal']];
                    }
                } else {
                    if ($out['base'] !== null) {
                        $lines[] = ['label' => 'Base fare', 'amount' => (float) $out['base']];
                    }
                    if (! empty($out['surcharge'])) {
                        $lines[] = ['label' => $out['surcharge']['label'], 'amount' => (float) $out['surcharge']['amount']];
                    }
                    foreach ($out['extras'] as $extra) {
                        $label = $extra['qty'] > 1 ? $extra['label'].' ×'.$extra['qty'] : $extra['label'];
                        $lines[] = ['label' => $label, 'amount' => (float) $extra['amount']];
                    }
                }

                return [
                    'id' => $vt->id,
                    'price' => $total,
                    'poa' => $poa,
                    'extras_total' => $out['extras_total'],
                    'breakdown' => $lines,
                    'formatted' => $poa || $total === null
                        ? 'POA'
                        : '£'.number_format((float) $total, 2),
                ];
            })->all();

        return response()->json(['options' => $options]);
    }
}
