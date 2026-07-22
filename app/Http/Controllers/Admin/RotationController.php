<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RotationLog;
use App\Services\RotationService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Read-only view of the driver rotation: the order Abdi and Maj take executive
 * saloon jobs, who is up next per airport, and the recent history of how the
 * pointer moved. Nothing here changes the rotation — allocation happens when a
 * booking is created (RotationService::allocate).
 */
class RotationController extends Controller
{
    public function index(Request $request, RotationService $rotation): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        $overview = $rotation->overview();

        $log = RotationLog::with(['fromDriver', 'toDriver', 'airport', 'vehicleType', 'booking'])
            ->latest()->limit(40)->get();

        return view('admin.rotation.index', [
            'drivers' => $overview['drivers'],
            'rows' => $overview['rows'],
            'log' => $log,
        ]);
    }

    /**
     * Operator override: set who's up next for one airport × vehicle type, so
     * the pointers can be brought exactly in line with the real-world order.
     * Logged as a manual override.
     */
    public function setNext(Request $request, RotationService $rotation): \Illuminate\Http\RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'airport_id' => ['required', \Illuminate\Validation\Rule::exists('airports', 'id')],
            'vehicle_type_id' => ['required', \Illuminate\Validation\Rule::exists('vehicle_types', 'id')],
            'driver_id' => ['required', \Illuminate\Validation\Rule::exists('users', 'id')],
        ]);

        $driver = \App\Models\User::findOrFail($data['driver_id']);
        // Only the two rotation drivers can be set as "up next".
        abort_unless($rotation->order()->contains('id', $driver->id), 422);

        $airport = \App\Models\Airport::findOrFail($data['airport_id']);
        $vehicleType = \App\Models\VehicleType::findOrFail($data['vehicle_type_id']);

        $rotation->setNextDriver($airport, $vehicleType, $driver);

        return back()->with('status', "{$airport->name} · {$vehicleType->name}: next up is now {$driver->name}.");
    }
}
