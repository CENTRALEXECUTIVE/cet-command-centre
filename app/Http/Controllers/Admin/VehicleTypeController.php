<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\VehicleType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin management of the fleet's vehicle TYPES — name, subtitle, capacities,
 * hand luggage, on/off and order — so staff control what customers see and pick,
 * with no code changes. Prices live in the Fixed-price and Free-roam editors;
 * photos in Fleet photos.
 */
class VehicleTypeController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        return view('admin.vehicles.index', [
            'vehicleTypes' => VehicleType::orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, VehicleType $vehicleType): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'tagline' => ['nullable', 'string', 'max:60'],
            'passenger_capacity' => ['required', 'integer', 'min:1', 'max:60'],
            'luggage_capacity' => ['required', 'integer', 'min:0', 'max:60'],
            'hand_luggage_capacity' => ['required', 'integer', 'min:0', 'max:60'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $vehicleType->update([
            'name' => $data['name'],
            'passenger_capacity' => (int) $data['passenger_capacity'],
            'luggage_capacity' => (int) $data['luggage_capacity'],
            'sort_order' => (int) $data['sort_order'],
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        // Tagline + hand luggage live in the shared vehicle_meta Setting (no column).
        $meta = (array) Setting::get('vehicle_meta', []);
        $meta[$vehicleType->slug] = [
            'tagline' => trim((string) ($data['tagline'] ?? '')) ?: null,
            'hand_luggage' => (int) $data['hand_luggage_capacity'],
        ];
        Setting::set('vehicle_meta', $meta, 'json', 'fleet');

        return back()->with('status', $vehicleType->name.' updated.');
    }
}
