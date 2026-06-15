<?php

namespace App\Http\Controllers;

use App\Models\FixedPrice;
use App\Models\PricingZone;
use App\Models\VehicleType;
use App\Services\Pricing\FixedPriceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin management of the fixed-price matrix — mirrors the ETO "Fixed Prices"
 * screen. A row is a zone → destination with a fare per vehicle type, so staff
 * enter/confirm the real numbers themselves (no error-prone bulk transcription).
 */
class FixedPriceController extends Controller
{
    public function __construct(private readonly FixedPriceService $fixedPrices) {}

    public function index(Request $request): View
    {
        $zones = PricingZone::orderBy('name')->get();
        $vehicleTypes = VehicleType::where('is_active', true)->orderBy('sort_order')->get();

        $query = FixedPrice::with(['pricingZone', 'vehicleType']);
        if ($zoneId = $request->integer('zone')) {
            $query->where('pricing_zone_id', $zoneId);
        }

        // Group the flat rows into zone → destination → [vehicle type => price].
        $rows = $query->get()
            ->groupBy(fn (FixedPrice $p) => $p->pricing_zone_id.'|'.$p->destination_slug)
            ->map(fn ($group) => [
                'zone' => $group->first()->pricingZone,
                'destination' => $group->first()->destination,
                'deposit' => $group->first()->deposit,
                'prices' => $group->keyBy('vehicle_type_id'),
            ])
            ->sortBy(fn ($r) => ($r['zone']?->name ?? '').$r['destination'])
            ->values();

        return view('pricing.index', compact('zones', 'vehicleTypes', 'rows'));
    }

    /** Upsert a whole zone→destination row (one price per vehicle type). */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pricing_zone_id' => ['required', Rule::exists('pricing_zones', 'id')],
            'destination' => ['required', 'string', 'max:120'],
            'deposit' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'prices' => ['required', 'array'],
            'prices.*' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);

        $zone = PricingZone::findOrFail($data['pricing_zone_id']);
        $deposit = (float) ($data['deposit'] ?? 0);
        $saved = 0;

        DB::transaction(function () use ($data, $zone, $deposit, &$saved) {
            foreach ($data['prices'] as $vehicleTypeId => $price) {
                $vehicleType = VehicleType::find($vehicleTypeId);
                if (! $vehicleType) {
                    continue;
                }
                if ($price === null || $price === '') {
                    // Blank clears any existing price for that class.
                    FixedPrice::where('pricing_zone_id', $zone->id)
                        ->where('destination_slug', $this->fixedPrices->destinationSlug($data['destination']))
                        ->where('vehicle_type_id', $vehicleType->id)->delete();

                    continue;
                }
                $this->fixedPrices->upsert($zone, $data['destination'], $vehicleType, (float) $price, $deposit);
                $saved++;
            }
        });

        return back()->with('status', "Saved {$saved} fare(s) for {$zone->name} → {$data['destination']}.");
    }

    public function destroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pricing_zone_id' => ['required', Rule::exists('pricing_zones', 'id')],
            'destination_slug' => ['required', 'string'],
        ]);

        FixedPrice::where('pricing_zone_id', $data['pricing_zone_id'])
            ->where('destination_slug', $data['destination_slug'])->delete();

        return back()->with('status', 'Route removed.');
    }
}
