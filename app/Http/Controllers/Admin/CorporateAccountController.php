<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\CorporateAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Business (corporate) accounts admin: the monthly-invoice accounts like MEPS,
 * JELD-WEN, LB Foster. Separate from the Customers directory — companies aren't
 * people — so this is where the office finds an account by name or account number,
 * sees its authorised contacts, and reviews its bookings.
 */
class CorporateAccountController extends Controller
{
    private const REVENUE = 'COALESCE(final_price, quoted_price, 0)';

    public function index(Request $request): View
    {
        $term = trim((string) $request->query('q', ''));

        $accounts = CorporateAccount::query()
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$term}%")
                ->orWhere('account_code', 'like', "%{$term}%")
                ->orWhere('vat_number', 'like', "%{$term}%")))
            ->withCount(['contacts', 'bookings'])
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString();

        return view('admin.accounts.index', ['accounts' => $accounts, 'term' => $term]);
    }

    public function show(CorporateAccount $account): View
    {
        $account->load('contacts');

        $bookings = $account->bookings()
            ->with(['vehicleType', 'driver', 'customer'])
            ->orderByDesc('pickup_at')
            ->limit(100)
            ->get();

        $lifetimeValue = (float) $account->bookings()
            ->whereNotIn('status', [BookingStatus::Cancelled->value, BookingStatus::NoShow->value])
            ->sum(DB::raw(self::REVENUE));

        return view('admin.accounts.show', [
            'account' => $account,
            'bookings' => $bookings,
            'lifetimeValue' => $lifetimeValue,
        ]);
    }
}
