<?php

namespace App\Http\Controllers;

use App\Models\VehicleType;
use App\Services\Pricing\QuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
}
