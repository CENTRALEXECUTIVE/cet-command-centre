<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BookingAttempt;
use App\Models\VehicleType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/** Read-only: every website booking attempt and "On request" price (26 Nov 2026). */
class BookingLogController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);
        $filter = $request->query('show', 'problems');
        $q = BookingAttempt::query()->orderByDesc('id');
        if ($filter === 'problems') {
            $q->whereIn('outcome', ['refused', 'error', 'quote_request', 'price_on_request', 'submitted']);
        }
        // Rolls-Royce-only "On request" is normal (always priced by the office): never shown.
        $q->where(fn ($w) => $w->where('outcome', '!=', 'price_on_request')
            ->orWhere('details', 'not like', '{"vehicles":["Rolls Royce Ghost"]%'));
        if ($s = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('customer_name', 'like', "%$s%")->orWhere('contact', 'like', "%$s%")
                ->orWhere('reference', 'like', "%$s%")->orWhere('pickup', 'like', "%$s%")->orWhere('destination', 'like', "%$s%"));
        }
        try {
            $attempts = $q->paginate(50)->withQueryString();
        } catch (\Throwable) {
            $attempts = null; // not migrated yet
        }

        return view('admin.booking-log.index', compact('attempts', 'filter'));
    }

    /** One entry in full: everything the customer typed. */
    public function show(Request $request, BookingAttempt $attempt): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        return view('admin.booking-log.show', ['a' => $attempt, 'fields' => $this->bookingFields($attempt)]);
    }

    /** Open the office New booking form filled in from this entry (nothing is saved yet). */
    public function book(Request $request, BookingAttempt $attempt): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        return redirect()->route('bookings.create')->withInput($this->bookingFields($attempt));
    }

    /** Map a log entry onto the office New booking form's fields. */
    private function bookingFields(BookingAttempt $a): array
    {
        $f = (array) (($a->details ?? [])['form'] ?? []);
        $contact = (string) $a->contact;
        preg_match('/[\w.+-]+@[\w.-]+\.\w+/', $contact, $em);
        preg_match('/\+?[\d][\d\s()-]{7,}/', $contact, $ph);
        $toLocal = function ($v) {
            if (! filled($v)) {
                return null;
            }
            try {
                return Carbon::parse((string) $v, config('app.timezone'))->format('Y-m-d\TH:i');
            } catch (\Throwable) {
                return null;
            }
        };
        $vehicle = $f['vehicle_type_id'] ?? (filled($a->details['vehicle'] ?? null)
            ? VehicleType::where('name', $a->details['vehicle'])->value('id') : null);
        $lead = $f['lead_passenger_name'] ?? trim(($f['lead_passenger_first_name'] ?? '').' '.($f['lead_passenger_surname'] ?? ''));
        $notes = trim(implode("\n", array_filter([
            $f['notes'] ?? null,
            ! empty($f['other_flight_number']) ? 'Other flight: '.$f['other_flight_number'] : null,
            ! empty($f['need_child_seat']) ? 'Child seat requested' : null,
            'From the website booking log (entry #'.$a->id.', '.$a->created_at?->timezone(config('app.timezone'))->format('d M H:i').')',
        ])));
        $isReturn = ($f['journey_type'] ?? null) === 'return' || filled($f['return_pickup_at'] ?? null);

        return array_filter([
            'customer_name' => $a->customer_name,
            'customer_email' => $f['customer_email'] ?? ($em[0] ?? null),
            'customer_phone' => $f['customer_phone'] ?? (isset($ph[0]) ? trim($ph[0]) : null),
            'lead_name' => $lead ?: null,
            'journey_type' => $isReturn ? 'return' : ($f['journey_type'] ?? null),
            'pickup_address' => $f['pickup_address'] ?? $a->pickup,
            'destination_address' => $f['destination_address'] ?? $a->destination,
            'pickup_at' => $toLocal($f['pickup_at'] ?? $a->pickup_at),
            'return_pickup_at' => $toLocal($f['return_pickup_at'] ?? null),
            'return_pickup_address' => $isReturn ? ($f['destination_address'] ?? $a->destination) : null,
            'return_destination_address' => $isReturn ? ($f['pickup_address'] ?? $a->pickup) : null,
            'vehicle_type_id' => $vehicle,
            'return_vehicle_type_id' => $isReturn ? $vehicle : null,
            'passengers' => $f['passengers'] ?? null,
            'suitcases' => $f['suitcases'] ?? null,
            'hand_luggage' => $f['hand_luggage'] ?? null,
            'flight_number' => $f['flight_number'] ?? null,
            'flight_landing_at' => $toLocal($f['flight_landing_at'] ?? null),
            'meet_greet' => $f['meet_greet'] ?? null,
            'vat_invoice' => $f['vat_invoice'] ?? null,
            'vat_company' => $f['business_name'] ?? null,
            'vat_number' => $f['business_vat_number'] ?? null,
            'vat_address' => $f['business_address'] ?? null,
            'quoted_price' => $a->details['price'] ?? null,
            'special_requests' => $notes,
            'voucher' => $f['voucher'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
