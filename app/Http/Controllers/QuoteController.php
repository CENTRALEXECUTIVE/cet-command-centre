<?php

namespace App\Http\Controllers;

use App\Models\FixedPrice;
use App\Models\PricingZone;
use App\Models\Quote;
use App\Models\VehicleType;
use App\Services\Pricing\AiPricingEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * AI pricing engine UI (admin & corporate): generate an instant quote in
 * seconds, review the breakdown, and convert it straight into a booking.
 */
class QuoteController extends Controller
{
    public function __construct(private readonly AiPricingEngine $engine) {}

    public function create(): View
    {
        return view('quotes.create', [
            'vehicleTypes' => VehicleType::where('is_active', true)->orderBy('sort_order')->get(),
            'zones' => PricingZone::where('is_active', true)->orderBy('name')->get(),
            'destinations' => FixedPrice::where('is_active', true)
                ->orderBy('destination')->distinct()->pluck('destination'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'vehicle_type_id' => ['required', Rule::exists('vehicle_types', 'id')],
            'pickup_address' => ['required', 'string', 'max:500'],
            'destination_address' => ['required', 'string', 'max:500'],
            'pickup_at' => ['required', 'date'],
            'is_airport' => ['nullable', 'boolean'],
            'distance_miles' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            // Fixed-price matrix inputs.
            'pricing_zone_id' => ['nullable', Rule::exists('pricing_zones', 'id')],
            'pickup_postcode' => ['nullable', 'string', 'max:16'],
            // Extras — priced from the CET surcharge list (config cet.surcharges).
            'meet_greet' => ['nullable', 'boolean'],
            'child_seats' => ['nullable', 'integer', 'min:0', 'max:8'],
            'booster_seats' => ['nullable', 'integer', 'min:0', 'max:8'],
            'infant_seats' => ['nullable', 'integer', 'min:0', 'max:8'],
            'stopovers' => ['nullable', 'integer', 'min:0', 'max:8'],
            'stopover_addresses' => ['nullable', 'string', 'max:1000'],
        ]);

        $quote = $this->engine->quote($data);

        return redirect()->route('quotes.show', $quote);
    }

    public function show(Quote $quote): View
    {
        return view('quotes.show', ['quote' => $quote->load('vehicleType')]);
    }
}
