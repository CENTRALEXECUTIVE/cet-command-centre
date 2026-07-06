<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Customer CRM (admin): a searchable directory and a per-customer profile with
 * their whole history, saved addresses, lifetime value and quick re-book.
 */
class CustomerController extends Controller
{
    private const REVENUE = 'COALESCE(final_price, quoted_price, 0)';

    public function index(Request $request): View
    {
        $term = trim((string) $request->query('q', ''));

        $customers = Customer::query()
            ->when($term !== '', function ($q) use ($term) {
                $q->where(fn ($w) => $w
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%"));
            })
            ->withCount('bookings')
            ->withMax('bookings', 'pickup_at')
            ->orderByDesc('is_vip')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('customers.index', ['customers' => $customers, 'term' => $term]);
    }

    public function show(Customer $customer): View
    {
        $customer->load(['addresses', 'preferredVehicleType', 'corporateAccount']);

        $bookings = $customer->bookings()
            ->with(['vehicleType', 'driver'])
            ->orderByDesc('pickup_at')
            ->limit(100)
            ->get();

        $lifetimeValue = (float) $customer->bookings()
            ->whereNotIn('status', [BookingStatus::Cancelled->value, BookingStatus::NoShow->value])
            ->sum(DB::raw(self::REVENUE));

        return view('customers.show', [
            'customer' => $customer,
            'bookings' => $bookings,
            'lifetimeValue' => $lifetimeValue,
            'tripCount' => $bookings->count(),
        ]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:160'],
            'is_vip' => ['nullable', 'boolean'],
            'marketing_consent' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $customer->fill([
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'is_vip' => (bool) ($data['is_vip'] ?? false),
            'notes' => $data['notes'] ?? null,
        ]);

        // Stamp the consent time when marketing consent is first turned on.
        $consent = (bool) ($data['marketing_consent'] ?? false);
        if ($consent && ! $customer->marketing_consent) {
            $customer->marketing_consent_at = now();
        }
        $customer->marketing_consent = $consent;
        $customer->save();

        return redirect()->route('customers.show', $customer)->with('status', 'Customer updated.');
    }

    /** Save a labelled address (home / work / …) to the customer. */
    public function storeAddress(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:40'],
            'address' => ['required', 'string', 'max:500'],
            'postcode' => ['nullable', 'string', 'max:16'],
        ]);

        $customer->addresses()->create($data);

        return back()->with('status', 'Address saved.');
    }

    public function destroyAddress(Customer $customer, \App\Models\CustomerAddress $address): RedirectResponse
    {
        abort_unless($address->customer_id === $customer->id, 404);
        $address->delete();

        return back()->with('status', 'Address removed.');
    }
}
