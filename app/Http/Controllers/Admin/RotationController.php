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
}
