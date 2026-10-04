<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\RotationService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The driver-rotation page: the "airport order" view — each airport's executive
 * bookings in the order they came through, with the rotation driver each was
 * given, and an inline driver change. Executive only, because that's what the
 * Abdi↔Maj rotation covers. Allocation itself happens when a booking is created
 * (RotationService::allocate); setNext() is the operator's pointer override.
 */
class RotationController extends Controller
{
    public function index(Request $request, \App\Services\RouteOrderService $routeOrder, RotationService $rotation): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        // The airport-order view: executive bookings per airport in the order they
        // came through, each with the rotation driver — EXECUTIVE ONLY, because
        // that's what the Abdi↔Maj rotation covers. Drivers can be changed inline.
        $order = $routeOrder->build($request->query('route'), $request->query('scope'), $request->query('vehicle'), executiveOnly: true);

        // Whose turn is it — and who did the last job — per airport × vehicle type,
        // so the running order is visible at a glance above the job list.
        $overview = $rotation->overview();

        // The rotation history: every allocation in order — whose turn it was, who
        // actually did it, for which job, and why (normal turn / paired return /
        // substitute / manual override). The running record of the Abdi↔Maj order.
        $history = \App\Models\RotationLog::with(['airport', 'vehicleType', 'fromDriver', 'toDriver', 'booking.customer'])
            ->latest('id')
            ->limit(60)
            ->get();

        return view('admin.rotation.index', [
            'rotationDrivers' => $overview['drivers'],
            'rotationRows' => $overview['rows'],
            'rotationHistory' => $history,
            'orderScope' => $order['scope'],
            'orderTabs' => $order['tabs'],
            'orderSelected' => $order['selected'],
            'orderVehicleTabs' => $order['vehicleTabs'],
            'orderSelectedVehicle' => $order['selectedVehicle'],
            'orderRows' => $order['rows'],
            'orderDrivers' => $order['drivers'],
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
