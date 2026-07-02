<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Route each role to the appropriate landing view with a scoped data set
     * (principle of least privilege — each role only sees its own data).
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            return view('dashboard.admin', [
                'todayCount' => Booking::whereDate('pickup_at', today())->count(),
                // "Awaiting allocation" = UPCOMING jobs with no driver yet. Past
                // pending jobs (old imports that already happened) are excluded —
                // otherwise the count balloons with history and is unactionable.
                'pendingCount' => Booking::where('status', BookingStatus::Pending->value)
                    ->where('pickup_at', '>=', today())
                    ->count(),
                'activeCount' => Booking::active()->count(),
                'upcoming' => Booking::with(['customer', 'vehicleType', 'driver'])
                    ->where('pickup_at', '>=', now())
                    ->orderBy('pickup_at')
                    ->limit(10)
                    ->get(),
            ]);
        }

        if ($user->isDriver()) {
            return view('dashboard.driver', [
                'todayJobs' => Booking::with(['customer', 'vehicleType'])
                    ->forDriver($user->id)
                    ->whereDate('pickup_at', today())
                    ->orderBy('pickup_at')
                    ->get(),
            ]);
        }

        // Corporate client: only their account's bookings.
        $accountIds = $user->corporateAccounts->pluck('id');

        return view('dashboard.corporate', [
            'bookings' => Booking::with(['vehicleType', 'driver'])
                ->whereIn('corporate_account_id', $accountIds)
                ->orderByDesc('pickup_at')
                ->limit(20)
                ->get(),
        ]);
    }
}
