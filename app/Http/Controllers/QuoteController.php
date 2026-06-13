<?php

namespace App\Http\Controllers;

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
        ]);

        $quote = $this->engine->quote($data);

        return redirect()->route('quotes.show', $quote);
    }

    public function show(Quote $quote): View
    {
        return view('quotes.show', ['quote' => $quote->load('vehicleType')]);
    }
}
