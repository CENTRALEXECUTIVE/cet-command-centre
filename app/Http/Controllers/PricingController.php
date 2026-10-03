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
            'stops' => ['nullable', 'integer', 'min:0', 'max:10'],
            'meet_greet' => ['nullable', 'boolean'],
            'child_seats' => ['nullable', 'integer', 'min:0', 'max:20'],
            'booster_seats' => ['nullable', 'integer', 'min:0', 'max:20'],
            'infant_seats' => ['nullable', 'integer', 'min:0', 'max:20'],
            'ribbons' => ['nullable', 'boolean'],
        ]);

        $pickupAt = ! empty($data['pickup_at']) ? Carbon::parse($data['pickup_at']) : now();
        $options = [
            'stopovers' => (int) ($data['stops'] ?? 0),
            'meet_greet' => (bool) ($data['meet_greet'] ?? false),
            'child_seats' => (int) ($data['child_seats'] ?? 0),
            'booster_seats' => (int) ($data['booster_seats'] ?? 0),
            'infant_seats' => (int) ($data['infant_seats'] ?? 0),
            'ribbons' => (bool) ($data['ribbons'] ?? false),
        ];

        $options = VehicleType::where('is_active', true)->orderBy('sort_order')->get()
            ->map(function (VehicleType $vt) use ($fares, $data, $pickupAt, $options) {
                $fare = $fares->calculate($data['pickup'], $data['destination'], $vt, $pickupAt, $options);
                $total = $fare['subtotal'];

                // A human-readable breakdown so the office sees exactly what makes
                // up the fare (base + any surcharge + each priced extra), not just
                // a total — the "live fare breakdown" the admin form was missing.
                $lines = [];
                if ($fare['base'] !== null) {
                    $lines[] = ['label' => 'Base fare', 'amount' => (float) $fare['base']];
                }
                if (! empty($fare['surcharge'])) {
                    $lines[] = ['label' => $fare['surcharge']['label'], 'amount' => (float) $fare['surcharge']['amount']];
                }
                foreach ($fare['extras'] as $extra) {
                    $label = $extra['qty'] > 1 ? $extra['label'].' ×'.$extra['qty'] : $extra['label'];
                    $lines[] = ['label' => $label, 'amount' => (float) $extra['amount']];
                }

                return [
                    'id' => $vt->id,
                    'price' => $total,
                    'poa' => $fare['poa'],
                    'extras_total' => $fare['extras_total'],
                    'breakdown' => $lines,
                    'formatted' => $fare['poa'] || $total === null
                        ? 'POA'
                        : '£'.number_format((float) $total, 2),
                ];
            })->all();

        return response()->json(['options' => $options]);
    }
}
