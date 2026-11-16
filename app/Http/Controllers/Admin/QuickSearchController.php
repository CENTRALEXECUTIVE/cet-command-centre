<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\CorporateAccount;
use App\Models\Customer;
use App\Models\Prospect;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The top search box (directors, 16 Nov 2026: "type anything — any name or anything —
 * and it should pop up with info"). One query across bookings, customers, drivers,
 * vehicles, business accounts and outreach companies, grouped, a few of each, each
 * linking to its page. Admin only; read-only.
 */
class QuickSearchController extends Controller
{
    private const PER_GROUP = 6;

    public function search(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['q' => $q, 'groups' => []]);
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
        $digits = preg_replace('/\D+/', '', $q);
        $phoneLikes = strlen($digits) >= 5
            ? collect(\App\Support\Phone::variants($q))->push($digits)->map(fn ($v) => '%'.preg_replace('/\D+/', '', (string) $v).'%')->unique()->values()->all()
            : [];
        $tz = config('app.timezone', 'Europe/London');

        $groups = [];

        // Bookings: reference, ETO ref, customer, lead passenger, addresses, flight, driver.
        $bookings = Booking::with(['customer', 'driver'])
            ->where(function ($w) use ($like, $phoneLikes) {
                $w->where('reference', 'like', $like)
                    ->orWhere('external_reference', 'like', $like)
                    ->orWhere('meta->lead_name', 'like', $like)
                    ->orWhere('pickup_address', 'like', $like)
                    ->orWhere('destination_address', 'like', $like)
                    ->orWhere('flight_number', 'like', $like)
                    ->orWhereHas('customer', function ($c) use ($like, $phoneLikes) {
                        $c->where('name', 'like', $like)->orWhere('email', 'like', $like);
                        foreach ($phoneLikes as $p) {
                            $c->orWhereRaw("replace(replace(replace(phone, ' ', ''), '+', ''), '-', '') like ?", [$p]);
                        }
                    })
                    ->orWhereHas('driver', fn ($d) => $d->where('name', 'like', $like)
                        ->orWhereHas('driverProfile', fn ($p) => $p->where('callsign', 'like', $like)));
            })
            ->orderByRaw('CASE WHEN pickup_at >= ? THEN 0 ELSE 1 END', [now()->startOfDay()])
            ->orderByDesc('pickup_at')
            ->limit(self::PER_GROUP)->get();
        if ($bookings->isNotEmpty()) {
            $groups[] = ['label' => 'Bookings', 'items' => $bookings->map(fn (Booking $b) => [
                'title' => ($b->displayName() ?: $b->customer?->name ?: 'Booking').' · '.($b->external_reference ?: $b->reference),
                'sub' => trim(($b->pickup_at?->copy()->timezone($tz)->format('D j M Y, H:i') ?? '').' · '
                    .\Illuminate\Support\Str::limit((string) $b->pickup_address, 28).' → '.\Illuminate\Support\Str::limit((string) $b->destination_address, 28)
                    .' · '.($b->status?->label() ?? '').($b->driver ? ' · '.$b->driver->name : '')),
                'url' => route('bookings.show', $b),
            ])->values()];
        }

        // Customers.
        $customers = Customer::query()
            ->where(function ($c) use ($like, $phoneLikes) {
                $c->where('name', 'like', $like)->orWhere('email', 'like', $like);
                foreach ($phoneLikes as $p) {
                    $c->orWhereRaw("replace(replace(replace(phone, ' ', ''), '+', ''), '-', '') like ?", [$p]);
                }
            })
            ->withCount('bookings')->orderByDesc('bookings_count')->limit(self::PER_GROUP)->get();
        if ($customers->isNotEmpty()) {
            $groups[] = ['label' => 'Customers', 'items' => $customers->map(fn (Customer $c) => [
                'title' => $c->name,
                'sub' => trim(implode(' · ', array_filter([\App\Support\Phone::display($c->phone), $c->email, $c->bookings_count.' booking'.($c->bookings_count === 1 ? '' : 's')]))),
                'url' => route('customers.show', $c),
            ])->values()];
        }

        // Drivers (and the directors who drive): name, phone, email, callsign, badge.
        $drivers = User::with('driverProfile')
            ->where(fn ($u) => $u->where('role', 'driver')->orWhereHas('driverProfile'))
            ->where(function ($u) use ($like, $phoneLikes) {
                $u->where('name', 'like', $like)->orWhere('email', 'like', $like)
                    ->orWhereHas('driverProfile', fn ($p) => $p->where('callsign', 'like', $like)->orWhere('phv_badge_number', 'like', $like));
                foreach ($phoneLikes as $p) {
                    $u->orWhereRaw("replace(replace(replace(phone, ' ', ''), '+', ''), '-', '') like ?", [$p]);
                }
            })
            ->orderBy('name')->limit(self::PER_GROUP)->get();
        if ($drivers->isNotEmpty()) {
            $groups[] = ['label' => 'Drivers', 'items' => $drivers->map(fn (User $d) => [
                'title' => $d->name.($d->driverProfile?->callsign ? ' ('.$d->driverProfile->callsign.')' : ''),
                'sub' => trim(implode(' · ', array_filter([\App\Support\Phone::display($d->phone), $d->is_active ? null : 'inactive', 'See their jobs →']))),
                'url' => route('bookings.index', ['driver' => $d->name]),
            ])->values()];
        }

        // Vehicles by registration / make / model.
        $vehicles = Vehicle::query()
            ->where(fn ($v) => $v->where('registration', 'like', $like)
                ->orWhere('registration', 'like', '%'.str_replace(' ', '', $q).'%')
                ->orWhere('make', 'like', $like)->orWhere('model', 'like', $like))
            ->limit(self::PER_GROUP)->get();
        if ($vehicles->isNotEmpty()) {
            $groups[] = ['label' => 'Vehicles', 'items' => $vehicles->map(fn (Vehicle $v) => [
                'title' => strtoupper((string) $v->registration),
                'sub' => trim(implode(' ', array_filter([$v->colour, $v->make, $v->model]))),
                'url' => route('compliance.index'),
            ])->values()];
        }

        // Business accounts.
        $accounts = CorporateAccount::query()
            ->where(fn ($a) => $a->where('name', 'like', $like)->orWhere('account_code', 'like', $like)->orWhere('billing_email', 'like', $like))
            ->limit(self::PER_GROUP)->get();
        if ($accounts->isNotEmpty()) {
            $groups[] = ['label' => 'Business accounts', 'items' => $accounts->map(fn (CorporateAccount $a) => [
                'title' => $a->name,
                'sub' => trim(implode(' · ', array_filter([$a->account_code, $a->billing_email]))),
                'url' => route('reports.business', $a),
            ])->values()];
        }

        // Outreach companies.
        $prospects = Prospect::query()
            ->where(fn ($p) => $p->where('company', 'like', $like)->orWhere('contact_email', 'like', $like)->orWhere('contact_name', 'like', $like))
            ->limit(self::PER_GROUP)->get();
        if ($prospects->isNotEmpty()) {
            $groups[] = ['label' => 'Outreach', 'items' => $prospects->map(fn (Prospect $p) => [
                'title' => $p->company,
                'sub' => trim(implode(' · ', array_filter([$p->contact_email, $p->statusLabel()]))),
                'url' => route('outreach.show', $p),
            ])->values()];
        }

        return response()->json([
            'q' => $q,
            'groups' => $groups,
            'all_bookings_url' => route('bookings.index', ['q' => $q]),
        ]);
    }
}
