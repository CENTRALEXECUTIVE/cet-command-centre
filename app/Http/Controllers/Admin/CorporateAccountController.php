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

    /**
     * Approve a business account (27 Nov 2026 — there was no way to switch a self-
     * registered account on). Turns on "Book on account" for its contacts and emails the
     * main contact once to say so. Admin-only, a deliberate office action.
     */
    public function approve(Request $request, CorporateAccount $account): \Illuminate\Http\RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $wasActive = (bool) $account->is_active;
        $account->forceFill(['is_active' => true])->save();

        $emailed = null;
        if (! $wasActive) {
            $contact = $account->contacts()->orderByDesc('is_primary')->first();
            $to = $contact?->email ?: $account->billing_email;
            if (filled($to)) {
                try {
                    \Illuminate\Support\Facades\Mail::to($to)->send(new \App\Mail\CustomerNoticeMail(
                        'Your business account with Central Executive Transfers is approved',
                        'Account approved',
                        'Good news'.($contact?->name ? ', '.$contact->name : '').': your business account for '.$account->name.' is now active (account no. '.$account->account_code.').',
                        [
                            'When you book online and sign in with your email and password, choose "Account" as the payment — no card needed.',
                            'Journeys are invoiced monthly with VAT, on '.$account->payment_terms_days.'-day terms.',
                            'Book here: '.url('/widget/book').' · Manage your bookings: '.url('/my-account'),
                        ],
                    ));
                    $emailed = $to;
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('[accounts] approval email failed: '.$e->getMessage());
                }
            }
        }

        return back()->with('status', $account->name.' approved — "Book on account" is on.'.($emailed ? ' Approval emailed to '.$emailed.'.' : ''));
    }

    /** Suspend a business account: "Book on account" switches off. Nobody is emailed. */
    public function suspend(Request $request, CorporateAccount $account): \Illuminate\Http\RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $account->forceFill(['is_active' => false])->save();

        return back()->with('status', $account->name.' suspended — "Book on account" is off. Existing bookings are unchanged.');
    }
}
