<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\CorporateAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Corporate portal — a corporate client's account statement: full booking
 * history for their account(s) with spend totals and cost-code breakdown.
 */
class CorporateController extends Controller
{
    public function statement(Request $request): View
    {
        $user = $request->user();
        $accountIds = $user->corporateAccounts->pluck('id');

        $bookings = Booking::with(['vehicleType', 'driver'])
            ->whereIn('corporate_account_id', $accountIds)
            ->orderByDesc('pickup_at')
            ->paginate(25);

        $spend = (float) Booking::whereIn('corporate_account_id', $accountIds)
            ->where('status', BookingStatus::Complete->value)
            ->sum(DB::raw('COALESCE(final_price, quoted_price, 0)'));

        return view('corporate.statement', [
            'accounts' => CorporateAccount::whereIn('id', $accountIds)->get(),
            'bookings' => $bookings,
            'totalSpend' => $spend,
            'completedCount' => Booking::whereIn('corporate_account_id', $accountIds)
                ->where('status', BookingStatus::Complete->value)->count(),
        ]);
    }
}
