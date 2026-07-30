<?php

namespace App\Services\Calendar;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a real booking from a "calendar only" job (an event on the Google
 * Calendar with no booking in the system). Used by the manual "add to bookings"
 * button AND the scheduled refresh, so a booking added straight onto the
 * calendar appears in the Command Centre automatically within minutes. The
 * existing calendar event is LINKED (never re-created) so the push sync can
 * never produce a duplicate.
 */
class CalendarJobImporter
{
    /**
     * Is there already a booking for this parsed event? Matched by linked
     * Google event id, then the reference, then a fuzzy match on the journey —
     * so the 5-minute import never creates a second booking for one already in
     * the system (the usual cause of "2 on the Command Centre, 1 on the calendar").
     */
    public function existingBookingFor(array $parsed): ?Booking
    {
        $byEvent = \App\Models\CalendarEvent::where('google_event_id', $parsed['event_id'])->first();
        if ($byEvent?->booking_id) {
            return $byEvent->booking;
        }

        if (! empty($parsed['reference'])) {
            if ($byRef = Booking::where('external_reference', $parsed['reference'])
                ->orWhere('reference', $parsed['reference'])->first()) {
                return $byRef;
            }
            // A reference merged into another booking must resolve to the survivor
            // rather than spawn a fresh duplicate off the calendar event.
            if ($byAlias = Booking::resolveByReference($parsed['reference'])) {
                return $byAlias;
            }
        }

        // No reference (or unmatched) → the event carries no id we know. Fall back
        // to the journey itself, but the caller's IDENTITY must match — same phone,
        // OR same customer name AND same drop-off. A shared drop-off ALONE is not
        // the same journey: two different customers going to the same airport at
        // the same minute are two real bookings, and matching on drop-off alone
        // would wrongly say the second is "already in bookings" and skip it.
        $pickup = $parsed['pickup_at'] ?? null;
        $phone = $parsed['customer_phone'] ?? null;
        $dropoff = $parsed['destination_address'] ?? null;
        $name = $parsed['customer_name'] ?? null;

        if ($pickup && ($phone || $name)) {
            $window = [$pickup->copy()->subMinute(), $pickup->copy()->addMinute()];

            if ($phone) {
                $byPhone = Booking::whereBetween('pickup_at', $window)
                    ->whereHas('customer', fn ($c) => $c->where('phone', $phone))
                    ->first();
                if ($byPhone) {
                    return $byPhone;
                }
            }

            if ($name && $dropoff) {
                return Booking::whereBetween('pickup_at', $window)
                    ->where('destination_address', $dropoff)
                    ->whereHas('customer', fn ($c) => $c->where('name', $name))
                    ->first();
            }
        }

        return null;
    }

    /**
     * Force-import a calendar job as a genuinely SEPARATE booking, after the
     * operator confirmed our matching tied it to the wrong existing booking (the
     * classic case: a return leg matched to its outbound). Clears the two ways it
     * gets wrongly claimed — a stale calendar-event link pointing this Google
     * event at another booking, and a merged-reference alias holding its
     * reference — then imports fresh. The Google Calendar itself is never touched;
     * we only tidy our local links.
     */
    public function importAsSeparate(array $parsed, ?User $creator = null): Booking
    {
        return DB::transaction(function () use ($parsed, $creator) {
            if (! empty($parsed['event_id'])) {
                \App\Models\CalendarEvent::where('google_event_id', $parsed['event_id'])->delete();
            }
            if (! empty($parsed['reference'])) {
                $this->releaseMergedReference($parsed['reference']);
            }

            return $this->import($parsed, $creator);
        });
    }

    /**
     * Drop a reference from another booking's merged_references alias list, so it
     * no longer claims a job the operator has said is separate. Only touches a
     * booking holding it as a MERGED alias — never one that owns it live.
     */
    private function releaseMergedReference(string $reference): void
    {
        $owner = Booking::resolveByReference($reference);
        if (! $owner || $owner->external_reference === $reference) {
            return;
        }

        $meta = $owner->meta ?? [];
        $meta['merged_references'] = array_values(array_filter(
            (array) ($meta['merged_references'] ?? []),
            fn ($r) => $r !== $reference,
        ));
        $owner->forceFill(['meta' => $meta])->save();
    }

    /**
     * Create the booking (+customer) and link the existing calendar event.
     *
     * @param  array<string, mixed>  $parsed  From CalendarStats::eventToBookingData()/rawEventToBookingData()
     */
    public function import(array $parsed, ?User $creator = null): Booking
    {
        return DB::transaction(function () use ($parsed, $creator) {
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
                'created_by' => $creator?->id,
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
