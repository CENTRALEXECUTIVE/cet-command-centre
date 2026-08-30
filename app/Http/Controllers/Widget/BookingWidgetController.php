<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\VehicleType;
use App\Services\Pricing\QuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Public, embeddable web-booking widgets (mirrors EasyTaxiOffice's Web Widgets):
 * a "mini" quick-price checker that a customer uses on the marketing site. Served
 * from the Command Centre and dropped into the website by iframe — the live site
 * itself is never touched. Uses the existing CET pricing (fixed matrix + free-roam
 * distance), so no AI cost and nothing is written per keystroke.
 */
class BookingWidgetController extends Controller
{
    public function __construct(private readonly QuoteService $quotes) {}

    /** Domains allowed to embed the widget in an iframe. */
    private function frameAncestors(): string
    {
        return "frame-ancestors 'self' https://centralexecutivetransfers.co.uk "
            .'https://*.centralexecutivetransfers.co.uk http://localhost:* http://127.0.0.1:*';
    }

    /** The mini quick-price widget page (embeddable). */
    public function mini(): \Illuminate\Http\Response
    {
        $vehicleTypes = VehicleType::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'slug', 'passenger_capacity', 'luggage_capacity']);

        return response()
            ->view('widget.mini', ['vehicleTypes' => $vehicleTypes])
            ->header('Content-Security-Policy', $this->frameAncestors());
    }

    /** Instant price for the widget (JSON). Lightweight — no AI, no saved quote. */
    public function price(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pickup' => ['required', 'string', 'max:500'],
            'destination' => ['required', 'string', 'max:500'],
            'vehicle_type_id' => ['required', Rule::exists('vehicle_types', 'id')],
        ]);

        $vehicleType = VehicleType::findOrFail($data['vehicle_type_id']);
        $result = $this->quotes->quote($data['pickup'], $data['destination'], $vehicleType);

        return response()->json([
            'price' => $result['price'],
            'basis' => $result['basis'],
            'fixed' => $result['fixed'],
            'formatted' => $result['price'] !== null ? '£'.number_format($result['price'], 0) : 'Price on request',
            'vehicle' => $vehicleType->name,
        ]);
    }
}
