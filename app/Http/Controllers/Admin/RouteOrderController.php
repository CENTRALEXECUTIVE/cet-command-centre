<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\RouteOrderService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Route order: pick a route (Free Roam, MAN, BHX, LHR, …) and see that route's
 * bookings in time order, each with the driver it's assigned to — so the office
 * can eyeball that jobs are going out in the right order per route. Also embedded
 * on the Driver rotation page (both use RouteOrderService).
 */
class RouteOrderController extends Controller
{
    public function index(Request $request, RouteOrderService $service): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        return view('admin.route-order.index', $service->build(
            $request->query('route'),
            $request->query('scope'),
        ));
    }
}
