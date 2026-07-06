<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\VehicleType;
use App\Services\Calendar\CalendarStats;
use App\Services\Messaging\BookingNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pulls a "calendar only" job (one that exists on the Google Calendar but not in
 * the booking database) into the system as a real booking, so it can be edited
 * and messaged. The existing calendar event is LINKED (not re-created), so the
 * calendar sync never pushes a duplicate.
 */
class CalendarJobController extends Controller
{
    public function __construct(
        private readonly CalendarStats $calendar,
        private readonly BookingNotifier $notifier,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate(['event_id' => ['required', 'string']]);

        $parsed = $this->calendar->eventToBookingData($data['event_id']);
        if (! $parsed || ! $parsed['pickup_at']) {
            return back()->with('status', 'Could not read that calendar event — try refreshing the day.');
        }

        // Already in the system? Just go there.
        if ($parsed['reference']) {
            $existing = Booking::where('external_reference', $parsed['reference'])
                ->orWhere('reference', $parsed['reference'])->first();
            if ($existing) {
                return redirect()->route('bookings.show', $existing)
                    ->with('status', 'This job is already in bookings.');
            }
        }

        $booking = DB::transaction(function () use ($parsed, $request) {
            $customer = $this->resolveCustomer($parsed);
            $vehicleType = $this->resolveVehicleType($parsed['vehicle_label']);
            [$method, $paymentStatus, $amount] = $this->resolvePayment($parsed['payment_text']);

            $booking = Booking::create([
                'reference' => Booking::generateReference(),
                'source_system' => 'calendar',
                'external_reference' => $parsed['reference'] ?: null,
                'customer_id' => $customer->id,
                'vehicle_type_id' => $vehicleType->id,
                'pickup_at' => $parsed['pickup_at'],
                'pickup_address' => $parsed['pickup_address'] ?: 'Unknown',
                'destination_address' => $parsed['destination_address'] ?: 'Unknown',
                'flight_number' => $parsed['flight_number'],
                'passengers' => $parsed['passengers'],
                'luggage' => $parsed['luggage'],
                'special_requests' => $parsed['notes'],
                'status' => BookingStatus::Pending->value,
                'quoted_price' => $amount,
                'payment_method' => $method,
                'payment_status' => $paymentStatus,
                'source' => 'calendar',
                'created_by' => $request->user()->id,
                'meta' => array_filter([
                    'driver_tag' => $parsed['driver_tag'],
                    'payment_text' => $parsed['payment_text'],
                    'luggage_text' => $parsed['luggage_text'],
                ]),
            ]);

            // Link the EXISTING Google event so the sync updates it in place and
            // never creates a duplicate.
            $booking->calendarEvent()->create([
                'calendar_id' => $parsed['calendar_id'],
                'google_event_id' => $parsed['event_id'],
                'title' => $parsed['title'],
                'location' => $parsed['location'],
                'description' => $parsed['description'],
                'start_at' => $parsed['pickup_at'],
                'end_at' => $parsed['end_at'] ?? $parsed['pickup_at']->copy()->addHour(),
                'timezone' => 'Europe/London',
                'sync_status' => 'synced',
                'synced_at' => now(),
            ]);

            return $booking;
        });

        $this->notifier->ensureReminders($booking->load('customer'));

        return redirect()->route('bookings.show', $booking)
            ->with('status', 'Added to bookings — you can now edit it and send the customer a message.');
    }

    private function resolveCustomer(array $parsed): Customer
    {
        $phone = $parsed['customer_phone'] ?: null;

        $customer = $phone ? Customer::where('phone', $phone)->first() : null;

        return $customer ?? Customer::create([
            'name' => $parsed['customer_name'] ?: 'Customer',
            'phone' => $phone,
            'preferred_pickup_address' => $parsed['pickup_address'] ?: null,
        ]);
    }

    private function resolveVehicleType(?string $label): VehicleType
    {
        $label = trim((string) $label);
        if ($label !== '') {
            $match = VehicleType::where('name', 'like', $label)
                ->orWhere('name', 'like', '%'.$label.'%')
                ->orWhere('slug', Str::slug($label))
                ->first();
            if ($match) {
                return $match;
            }
        }

        return VehicleType::where('slug', 'executive')->first()
            ?? VehicleType::orderBy('id')->firstOrFail();
    }

    /**
     * Infer payment method, status and any amount from the calendar's payment
     * line, e.g. "Paid £100 (Stripe)" or "Pending (Account)".
     *
     * @return array{0: string, 1: string, 2: ?float}
     */
    private function resolvePayment(?string $text): array
    {
        $t = Str::lower((string) $text);
        $method = str_contains($t, 'cash') ? 'cash' : (str_contains($t, 'account') ? 'account' : 'card');
        $status = str_contains($t, 'paid') ? 'paid' : 'pending';
        $amount = preg_match('/£\s*([\d,]+(?:\.\d+)?)/', (string) $text, $m)
            ? (float) str_replace(',', '', $m[1])
            : null;

        return [$method, $status, $amount];
    }
}
