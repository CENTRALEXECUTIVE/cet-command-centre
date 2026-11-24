<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PhoneCall;
use App\Models\VehicleType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Calls answered by the AI phone agent. Booking requests are confirmed via Paste a booking. */
class PhoneCallController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);
        $calls = PhoneCall::latest()->paginate(30);

        return view('admin.phone-calls.index', [
            'calls' => $calls,
            'open' => PhoneCall::where('needs_action', true)->whereNull('reviewed_at')->count(),
        ]);
    }

    public function show(Request $request, PhoneCall $call): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        return view('admin.phone-calls.show', ['call' => $call, 'fields' => $this->intakeFields($call)]);
    }

    public function reviewed(Request $request, PhoneCall $call): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $call->update(['reviewed_at' => now(), 'needs_action' => false]);

        return redirect()->route('phone-calls.index')->with('status', 'Marked as dealt with.');
    }

    /** The booking request in the shape Paste a booking's preview expects. */
    public function intakeFields(PhoneCall $call): ?array
    {
        $f = $call->booking_fields;
        if (! $f) {
            return null;
        }
        $vehicle = VehicleType::where('slug', $f['vehicle'] ?? '')->value('name') ?? 'Executive';
        $notes = array_filter([
            $f['notes'] ?? null,
            ! empty($f['return_pickup_at']) ? 'Return pickup: '.$f['return_pickup_at'] : null,
            'Taken by the AI phone agent from '.($call->from_number ?: 'unknown number').'.',
        ]);

        return [
            'lead_name' => $f['lead_name'] ?? '', 'contact_no' => $f['contact_no'] ?? '', 'email' => $f['email'] ?? '',
            'pickup_at' => $f['pickup_at'] ?? '', 'pickup_address' => $f['pickup_address'] ?? '',
            'destination_address' => $f['destination_address'] ?? '', 'flight_number' => $f['flight_number'] ?? '',
            'passengers' => (int) ($f['passengers'] ?? 1), 'suitcases' => (int) ($f['suitcases'] ?? 0),
            'hand_luggage' => (int) ($f['hand_luggage'] ?? 0), 'vehicle' => $vehicle,
            'payment' => $f['payment'] ?? 'card', 'price' => $f['price'] ?? '', 'booked_by' => $f['booked_by'] ?? '',
            'notes' => implode(' ', $notes),
        ];
    }
}
