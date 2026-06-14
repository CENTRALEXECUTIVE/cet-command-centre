<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\VehicleType;
use App\Models\WaitingListEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Waiting list management (admin). Add customers waiting for a date; they are
 * auto-notified by WhatsApp when a matching booking is cancelled.
 */
class WaitingListController extends Controller
{
    public function index(): View
    {
        return view('waiting-list.index', [
            'entries' => WaitingListEntry::with(['customer', 'vehicleType'])
                ->orderByDesc('desired_date')->paginate(25),
            'vehicleTypes' => VehicleType::where('is_active', true)->orderBy('sort_order')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_phone' => ['required', 'string', 'max:32'],
            'desired_date' => ['required', 'date', 'after_or_equal:today'],
            'desired_time_window' => ['nullable', 'string', 'max:60'],
            'vehicle_type_id' => ['nullable', Rule::exists('vehicle_types', 'id')],
            'pickup_address' => ['nullable', 'string', 'max:500'],
            'destination_address' => ['nullable', 'string', 'max:500'],
        ]);

        $customer = Customer::firstOrCreate(
            ['phone' => $data['customer_phone']],
            ['name' => $data['customer_name']],
        );

        WaitingListEntry::create([
            'customer_id' => $customer->id,
            'vehicle_type_id' => $data['vehicle_type_id'] ?? null,
            'desired_date' => $data['desired_date'],
            'desired_time_window' => $data['desired_time_window'] ?? null,
            'pickup_address' => $data['pickup_address'] ?? null,
            'destination_address' => $data['destination_address'] ?? null,
            'status' => 'waiting',
        ]);

        return back()->with('status', "{$customer->name} added to the waiting list.");
    }
}
