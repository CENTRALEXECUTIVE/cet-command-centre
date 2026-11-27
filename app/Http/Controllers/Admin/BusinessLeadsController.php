<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Business leads (27 Nov 2026): past customers who booked with a COMPANY email address
 * (not Gmail/Hotmail etc.) and aren't on a business account yet — warm leads for a
 * personal "open an account" email. Read-only; nothing is sent from here.
 */
class BusinessLeadsController extends Controller
{
    public const FREE_MAIL = ['gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'hotmail.co.uk', 'live.com', 'live.co.uk',
        'yahoo.com', 'yahoo.co.uk', 'icloud.com', 'me.com', 'mac.com', 'aol.com', 'aol.co.uk', 'btinternet.com', 'sky.com', 'msn.com',
        'protonmail.com', 'proton.me', 'talktalk.net', 'virginmedia.com', 'ntlworld.com', 'mail.com', 'gmx.com', 'gmx.co.uk', 'ymail.com',
        'outlook.co.uk', 'blueyonder.co.uk', 'tiscali.co.uk', 'example.com'];

    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        $customers = Customer::query()
            ->whereNotNull('email')->where('email', 'like', '%@%')
            ->whereNull('corporate_account_id')
            ->get(['id', 'name', 'email', 'phone']);

        $leads = $customers
            ->map(function (Customer $c) {
                $domain = strtolower(trim(substr((string) strrchr((string) $c->email, '@'), 1)));

                return ['customer' => $c, 'domain' => $domain];
            })
            ->reject(fn ($r) => $r['domain'] === '' || in_array($r['domain'], self::FREE_MAIL, true)
                || str_ends_with($r['domain'], 'centralexecutivetransfers.co.uk') || str_ends_with($r['domain'], 'centralexecutivetransferspvt.co.uk')
                || str_ends_with($r['domain'], 'cet-drivers.local'))
            ->values();

        $ids = $leads->pluck('customer.id');
        $stats = Booking::query()->whereIn('customer_id', $ids)
            ->where('is_return_leg', false)
            ->selectRaw('customer_id, count(*) as trips, max(pickup_at) as last_trip, sum(coalesce(final_price, quoted_price, 0)) as spent')
            ->groupBy('customer_id')->get()->keyBy('customer_id');

        $rows = $leads->map(fn ($r) => $r + [
            'trips' => (int) ($stats[$r['customer']->id]->trips ?? 0),
            'last_trip' => $stats[$r['customer']->id]->last_trip ?? null,
            'spent' => (float) ($stats[$r['customer']->id]->spent ?? 0),
        ])->filter(fn ($r) => $r['trips'] > 0)
          ->sortByDesc(fn ($r) => [$r['trips'], (string) $r['last_trip']])
          ->values();

        // One row per company domain (several bookers at one firm = a stronger lead).
        $byDomain = $rows->groupBy('domain')->map(fn ($g, $domain) => [
            'domain' => $domain,
            'people' => $g->pluck('customer'),
            'trips' => $g->sum('trips'),
            'spent' => $g->sum('spent'),
            'last_trip' => $g->max('last_trip'),
        ])->sortByDesc('trips')->values();

        return view('admin.business-leads.index', ['companies' => $byDomain]);
    }
}
