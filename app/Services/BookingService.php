<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\Consent;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\Messaging\BookingNotifier;
use App\Services\Payments\PaymentService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Creates bookings from validated form input: resolves/creates the customer,
 * builds the outbound (and optional return) legs, persists via stops, records
 * GDPR consent, scaffolds the calendar event and runs the rotation engine.
 */
class BookingService
{
    public function __construct(
        private readonly RotationService $rotation,
        private readonly CalendarEventBuilder $calendar,
        private readonly BookingNotifier $notifier,
        private readonly PaymentService $payments,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated StoreBookingRequest data.
     */
    public function createFromForm(array $data, ?User $creator): Booking
    {
        return DB::transaction(function () use ($data, $creator) {
            $customer = $this->resolveCustomer($data);
            $this->recordPrivacyConsent($customer, $data);

            // A voucher code typed on the admin form reduces the customer price the
            // same way the customer/widget flow does, and is recorded for the office.
            [$data, $voucherMeta] = $this->applyVoucherDiscount($data);

            $outbound = $this->buildLeg($data, $customer, $creator, isReturn: false);

            if ($voucherMeta) {
                $outbound->forceFill(['meta' => array_merge($outbound->meta ?? [], $voucherMeta)])->save();
            }

            if (($data['journey_type'] ?? 'one_way') === 'return') {
                $return = $this->buildLeg($data, $customer, $creator, isReturn: true);
                // Link the pair both ways so the rotation engine can honour the
                // "same driver, move once" rule.
                $outbound->linked_booking_id = $return->id;
                $outbound->save();
                $return->linked_booking_id = $outbound->id;
                $return->save();

                // Allocate outbound first (advances rotation once), then the
                // return inherits the same driver without advancing.
                $this->rotation->allocate($outbound);
                $this->rotation->allocate($return);
                $this->calendar->buildFor($return);
            } else {
                $this->rotation->allocate($outbound);
            }

            $this->calendar->buildFor($outbound->refresh());

            // Payment: Tide link for card, cash flagged, account left to invoice.
            $this->payments->createForBooking($outbound);

            // Automated WhatsApp: instant confirmation + 24h/2h reminders.
            $outbound->loadMissing(['customer', 'vehicleType']);
            $this->notifier->sendConfirmation($outbound);
            $this->notifier->scheduleReminders($outbound);

            return $outbound;
        });
    }

    /**
     * Apply a voucher code typed on the admin booking form. Mirrors the
     * customer/widget flow: a redeemable code reduces the outbound customer price
     * (the fare), and the code is always recorded in the notes for the office even
     * when it can't be validated. Returns [$data (price + notes adjusted), $meta]
     * where $meta carries voucher_code and any discount for the booking's meta.
     * Driver pay is deliberately NOT auto-reduced — a discounted booking leaves
     * the payroll box blank for the office to set by hand (see suggestedDriverPay).
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null}
     */
    private function applyVoucherDiscount(array $data): array
    {
        $code = strtoupper(trim((string) ($data['voucher'] ?? '')));
        if ($code === '') {
            return [$data, null];
        }

        $price = $data['quoted_price'] ?? null;
        $voucher = \App\Models\Voucher::findByCode($code);
        $discount = ($voucher && $voucher->isRedeemable() && $price !== null && (float) $price > 0)
            ? $voucher->discountOn((float) $price)
            : 0.0;

        if ($discount > 0) {
            $data['quoted_price'] = round((float) $price - $discount, 2);
        }

        $note = 'Discount code: '.$code.($discount > 0
            ? ' (−£'.number_format($discount, 2).')'
            : ' (not applied)');
        $data['special_requests'] = trim((! empty($data['special_requests']) ? $data['special_requests']."\n" : '').$note);

        $meta = array_filter([
            'voucher_code' => $code,
            'discount' => $discount > 0 ? round($discount, 2) : null,
        ], fn ($v) => $v !== null);

        return [$data, $meta];
    }

    /**
     * Amend an existing booking from the edit form: update the customer's contact
     * details, the booking's own fields and its via stops, then rebuild the
     * calendar event (updateOrCreate keeps the same event and flags it for
     * re-sync). The assigned driver and rotation are left untouched.
     *
     * @param  array<string, mixed>  $data  Validated UpdateBookingRequest data.
     */
    public function updateFromForm(Booking $booking, array $data): Booking
    {
        return DB::transaction(function () use ($booking, $data) {
            // The contact number the booking CURRENTLY shows (override → calendar
            // "Contact No" → customer phone). We compare the office's edit against
            // this so a genuinely changed number sticks — see the override below.
            $priorContact = $booking->customerContactNumber();

            // Keep the customer's contact details current.
            if ($customer = $booking->customer) {
                $customer->fill(array_filter([
                    'name' => $data['customer_name'] ?? null,
                    'phone' => $data['customer_phone'] ?? null,
                    'email' => $data['customer_email'] ?? null,
                ], fn ($v) => $v !== null && $v !== ''))->save();
            }

            // "I'm the boss — listen to what I input." When the office edits the
            // contact number on the booking to something OTHER than what's shown,
            // pin it as this booking's override so it wins over a stale ETO calendar
            // "Contact No" (which otherwise beats the customer's phone and makes the
            // edit look like it "reverted"). Compared on digits so formatting/+44
            // differences aren't mistaken for a change.
            $submittedPhone = trim((string) ($data['customer_phone'] ?? ''));
            $digits = fn ($v) => substr(preg_replace('/\D/', '', (string) $v), -9);
            $contactOverride = ($submittedPhone !== '' && $digits($submittedPhone) !== $digits($priorContact))
                ? $submittedPhone : null;

            [$suitcases, $handLuggage, $luggage] = $this->luggageFrom($data);

            $pax = max(1, (int) ($data['passengers'] ?? 1));
            // GUARD: never store more of any seat type than there are passengers.
            $childCap = min((int) ($data['child_seats'] ?? 0), $pax);
            $boosterCap = min((int) ($data['booster_seats'] ?? 0), $pax);
            $infantCap = min((int) ($data['infant_seats'] ?? 0), $pax);
            $driverNotes = trim((string) ($data['driver_notes'] ?? '')) ?: null;
            $vehicleName = optional(VehicleType::find($data['vehicle_type_id'] ?? null))->name;

            // Extras the driver needs flagged (structured, from the tick-boxes).
            $ribbon = (bool) ($data['ribbon'] ?? false);
            $waitingTime = ! empty($data['waiting'])
                ? array_filter([
                    'where' => $data['waiting_where'] ?? null,
                    'minutes' => isset($data['waiting_minutes']) && $data['waiting_minutes'] !== null
                        ? (int) $data['waiting_minutes'] : null,
                ], fn ($v) => $v !== null && $v !== '')
                : null;

            // Work out WHICH fields the office actually changed, by comparing each
            // submitted value against what the booking currently DISPLAYS (the
            // calendar, where it's the source). Only these fields override the
            // calendar afterwards — every untouched field keeps mirroring it, so
            // an edit to one field never blanks or stales another.
            $editedFields = $this->changedFields($booking, [
                'pickup_at' => [
                    optional($booking->pickup_at)->format('Y-m-d H:i'),
                    $this->normaliseDateTime($data['pickup_at'] ?? null),
                ],
                'pickup_address' => [$booking->displayPickupAddress(), $data['pickup_address'] ?? null],
                'destination_address' => [$booking->displayDropoffAddress(), $data['destination_address'] ?? null],
                'flight_number' => [$booking->displayFlightNumber(), $data['flight_number'] ?? null],
                'passengers' => [$booking->passengerCount(), $data['passengers'] ?? null],
                'vehicle_type' => [$booking->displayVehicleType(), $vehicleName],
                'customer_name' => [$booking->displayName(), $data['customer_name'] ?? null],
                'luggage' => [$booking->displaySuitcases().'+'.$booking->displayHandLuggage(), $suitcases.'+'.$handLuggage],
                'child_seats' => [
                    ($booking->meta['child_seats'] ?? 0).'/'.($booking->meta['booster_seats'] ?? 0).'/'.($booking->meta['infant_seats'] ?? 0),
                    $childCap.'/'.$boosterCap.'/'.$infantCap,
                ],
            ]);

            // Via stops need their OWN check: changedFields ignores a change to an
            // empty value (so blanking a required field isn't taken as an edit), but
            // for vias an empty list is a real edit — the office REMOVED the stop.
            // Mark it edited whenever the submitted list differs, empty included, so
            // the removal wins over the calendar for good.
            if (array_key_exists('via_stops', $data)) {
                $submittedVia = implode(' | ', array_values(array_filter(array_map('trim', $data['via_stops'] ?? []))));
                $currentVia = implode(' | ', $booking->viaStops());
                if (mb_strtolower(trim($submittedVia)) !== mb_strtolower(trim($currentVia))) {
                    $editedFields[] = 'via_stops';
                }
            }

            // A later edit adds to the set — never drops a field edited before.
            $editedFields = array_values(array_unique(array_merge(
                (array) ($booking->meta['edited_fields'] ?? []), $editedFields,
            )));

            $booking->fill([
                'vehicle_type_id' => $data['vehicle_type_id'],
                'airport_id' => $data['airport_id'] ?? null,
                'pickup_at' => $data['pickup_at'],
                'pickup_address' => $data['pickup_address'],
                'destination_address' => $data['destination_address'],
                'flight_number' => $data['flight_number'] ?? null,
                'passengers' => $data['passengers'],
                'luggage' => $luggage,
                'meta' => array_merge($booking->meta ?? [], [
                    // Keep the imported lead_name in step with an edited customer
                    // name, so the name shows consistently everywhere (driver link
                    // included), not just where the customer record is read.
                    'lead_name' => $data['customer_name'] ?? ($booking->meta['lead_name'] ?? null),
                    'suitcases' => $suitcases,
                    'hand_luggage' => $handLuggage,
                    'child_seats' => $childCap,
                    'booster_seats' => $boosterCap,
                    'infant_seats' => $infantCap,
                    'child_seat' => ($childCap + $boosterCap + $infantCap) > 0,
                    'driver_notes' => $driverNotes,
                    'ribbon' => $ribbon,
                    'waiting_time' => $waitingTime, // null when unticked
                    'wait_and_return' => $waitingTime !== null, // legacy/convenience flag
                    // Mark the booking edited, and record exactly which fields the
                    // office changed. Untouched fields keep mirroring the calendar
                    // (the source of truth); only edited fields win over it.
                    'manually_edited_at' => now()->toIso8601String(),
                    'edited_fields' => $editedFields,
                    // An explicitly changed contact number wins over the calendar;
                    // an unchanged one leaves any existing override untouched.
                    'contact_override' => $contactOverride ?: ($booking->meta['contact_override'] ?? null),
                ]),
                'special_requests' => $data['special_requests'] ?? null,
                'payment_method' => $data['payment_method'],
                'payment_status' => $data['payment_status'] ?? $booking->payment_status,
                'quoted_price' => $data['quoted_price'] ?? null,
                'final_price' => $data['final_price'] ?? null,
            ])->save();

            // Keep the geocoded coordinates in step with an edited address, so the
            // driver's Waze / Google Maps links (which navigate to the exact point)
            // don't send them to a same-named place miles away — e.g. a different
            // "Whitby's Fish & Chips" branch. Re-geocode the changed end(s); a null
            // result just falls back to an address search, never a stale pin.
            if (array_intersect(['pickup_address', 'destination_address'], $editedFields)) {
                $geocoder = app(\App\Services\GeocodingService::class);
                $geo = $booking->meta['geo'] ?? [];
                if (in_array('pickup_address', $editedFields, true)) {
                    $geo['pickup'] = $geocoder->coords($booking->pickup_address);
                }
                if (in_array('destination_address', $editedFields, true)) {
                    $geo['dropoff'] = $geocoder->coords($booking->destination_address);
                }
                $booking->forceFill(['meta' => array_merge($booking->meta ?? [], ['geo' => $geo])])->save();
            }

            // The booking reference (covering / non-ETO jobs). Only settable here;
            // trimmed, and cleared to null when blanked.
            if (array_key_exists('external_reference', $data)) {
                $ref = trim((string) ($data['external_reference'] ?? ''));
                $refChanged = $ref !== (string) $booking->external_reference;
                $booking->forceFill(['external_reference' => $ref ?: null])->save();

                // For a job WE created (intake / web / manual) we own the calendar
                // event, so rebuild it to show the corrected reference. A
                // calendar- or ETO-sourced booking is left alone (that event is the
                // operator's to manage by hand — never push an amendment to it).
                if ($refChanged && ! in_array($booking->source_system, ['calendar', 'eto'], true)) {
                    $this->calendar->buildFor($booking->fresh(['customer', 'vehicleType', 'airport', 'driver']));
                }
            }

            // Re-sync via stops from the submitted list whenever the edit form
            // included the via field — for ANY leg, so removing or changing a via
            // always persists (a return leg is still the office's to edit; the old
            // "outbound only" rule silently dropped return-leg via edits).
            if (array_key_exists('via_stops', $data)) {
                $booking->stops()->delete();
                foreach (array_values(array_filter(array_map('trim', Arr::get($data, 'via_stops', []) ?? []))) as $i => $address) {
                    $booking->stops()->create(['sequence' => $i + 1, 'address' => $address]);
                }
            }

            // Deliberately DO NOT touch the calendar on an edit. New bookings are
            // auto-added, but edits are the operator's to make on the calendar by
            // hand — the system never pushes an amendment to Google. The booking's
            // own display accessors (displayPickupAddress, viaStops, …) already show
            // the edit everywhere in the app and to drivers.

            return $booking->refresh();
        });
    }

    /**
     * Given [field => [currentlyDisplayed, submitted]] pairs, return the keys
     * whose submitted value is non-blank and actually differs from what the
     * booking currently shows. Whitespace/case-insensitive so a cosmetic
     * re-type isn't counted as a change.
     *
     * @param  array<string, array{0:mixed,1:mixed}>  $pairs
     * @return array<int, string>
     */
    /** Normalise a submitted datetime-local value to "Y-m-d H:i" for comparison. */
    private function normaliseDateTime(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse($value)->format('Y-m-d H:i');
        } catch (\Throwable) {
            return null;
        }
    }

    private function changedFields(Booking $booking, array $pairs): array
    {
        $norm = fn ($v) => strtolower(trim(preg_replace('/\s+/', ' ', (string) ($v ?? ''))));
        $changed = [];
        foreach ($pairs as $key => [$before, $after]) {
            if ($norm($after) !== '' && $norm($before) !== $norm($after)) {
                $changed[] = $key;
            }
        }

        return $changed;
    }

    private function resolveCustomer(array $data): Customer
    {
        $phone = $data['customer_phone'] ?? null;
        $email = $data['customer_email'] ?? null;

        // "Remember every customer": match on PHONE first; fall back to email
        // only when the booking has no phone. Never merge a phoned booking into a
        // record with a different phone just because an email coincides — that
        // silently files it under the wrong person and texts the wrong number.
        $customer = null;
        if ($phone) {
            $customer = Customer::where('phone', $phone)->first();
        }
        if (! $customer && ! $phone && $email) {
            $customer = Customer::where('email', $email)->first();
        }

        if (! $customer) {
            $customer = Customer::create([
                'name' => $data['customer_name'],
                'phone' => $phone,
                'email' => $email,
                'corporate_account_id' => $data['corporate_account_id'] ?? null,
                'preferred_pickup_address' => $data['pickup_address'],
                'preferred_vehicle_type_id' => $data['vehicle_type_id'],
            ]);
        }

        return $customer;
    }

    private function recordPrivacyConsent(Customer $customer, array $data): void
    {
        Consent::create([
            'subject_type' => 'customer',
            'subject_id' => $customer->id,
            'email' => $customer->email,
            'type' => 'privacy_notice',
            'granted' => true,
            'version' => config('cet.privacy_policy_version', '1.0'),
            'ip_address' => request()->ip(),
            'granted_at' => now(),
        ]);
    }

    /**
     * Normalise luggage from the form into [suitcases, hand luggage, combined].
     * The form now captures the two counts separately; the combined `luggage`
     * column is kept in sync (falling back to a legacy single `luggage` field).
     *
     * @param  array<string, mixed>  $data
     * @return array{0:int,1:int,2:int}
     */
    private function luggageFrom(array $data): array
    {
        $suitcases = (int) ($data['suitcases'] ?? 0);
        $handLuggage = (int) ($data['hand_luggage'] ?? 0);
        $combined = ($suitcases > 0 || $handLuggage > 0)
            ? $suitcases + $handLuggage
            : (int) ($data['luggage'] ?? 0);

        return [$suitcases, $handLuggage, $combined];
    }

    private function buildLeg(array $data, Customer $customer, ?User $creator, bool $isReturn): Booking
    {
        $vehicleType = VehicleType::findOrFail($data['vehicle_type_id']);

        $isHourly = ($data['journey_type'] ?? 'one_way') === 'hourly';
        $hourlyHours = $isHourly ? (int) ($data['hours'] ?? 0) : null;

        $pickupAt = $isReturn ? $data['return_pickup_at'] : $data['pickup_at'];
        $pickupAddress = $isReturn ? $data['destination_address'] : $data['pickup_address'];
        // Hourly hire has no fixed destination — the car is "as directed".
        $destinationAddress = $isHourly
            ? 'As directed (hourly hire)'
            : ($isReturn ? $data['pickup_address'] : $data['destination_address']);

        [$suitcases, $handLuggage, $luggage] = $this->luggageFrom($data);

        $booking = Booking::create([
            'reference' => Booking::generateReference(),
            'customer_id' => $customer->id,
            'corporate_account_id' => $data['corporate_account_id'] ?? null,
            'cost_code' => $data['cost_code'] ?? null,
            'corporate_reference' => $data['corporate_reference'] ?? null,
            'vehicle_type_id' => $vehicleType->id,
            'airport_id' => $data['airport_id'] ?? null,
            'journey_type' => $data['journey_type'] ?? 'one_way',
            'is_return_leg' => $isReturn,
            'pickup_at' => $pickupAt,
            'pickup_address' => $pickupAddress,
            'pickup_postcode' => $isReturn ? ($data['destination_postcode'] ?? null) : ($data['pickup_postcode'] ?? null),
            'destination_address' => $destinationAddress,
            'destination_postcode' => $isReturn ? ($data['pickup_postcode'] ?? null) : ($data['destination_postcode'] ?? null),
            'flight_number' => $data['flight_number'] ?? null,
            'passengers' => $data['passengers'],
            'luggage' => $luggage,
            'meta' => array_filter([
                'suitcases' => $suitcases,
                'hand_luggage' => $handLuggage,
                'driver_notes' => trim((string) ($data['driver_notes'] ?? '')) ?: null,
                'hourly_hours' => $hourlyHours,
                // Extras — same set the customer widget captures (child-seat counts are
                // capped at the passenger count), so the calendar marks and the driver
                // sheet show them.
                'meet_greet' => ! empty($data['meet_greet']) ?: null,
                // Flight landing time (airport arrivals) — kept so the office can
                // track the flight and time the pickup; UK-local like all CET times.
                'flight_landing_at' => ! empty($data['flight_landing_at'])
                    ? \Illuminate\Support\Carbon::parse($data['flight_landing_at'], config('app.timezone'))->toDateTimeString()
                    : null,
                'child_seats' => min((int) ($data['child_seats'] ?? 0), (int) $data['passengers']) ?: null,
                'booster_seats' => min((int) ($data['booster_seats'] ?? 0), (int) $data['passengers']) ?: null,
                'infant_seats' => min((int) ($data['infant_seats'] ?? 0), (int) $data['passengers']) ?: null,
                'child_seat' => (((int) ($data['child_seats'] ?? 0)) + ((int) ($data['booster_seats'] ?? 0)) + ((int) ($data['infant_seats'] ?? 0))) > 0 ?: null,
                'ribbon' => ! empty($data['ribbon']) ?: null,
                'wheelchair' => ! empty($data['wheelchair']) ?: null,
            ], fn ($v) => $v !== null && $v !== '' && $v !== false),
            'special_requests' => $data['special_requests'] ?? null,
            'status' => BookingStatus::Pending,
            // The quoted price is the TOTAL for the journey — keep it on the
            // outbound leg only so a return isn't double-counted in revenue.
            'quoted_price' => $isReturn ? null : ($data['quoted_price'] ?? null),
            'payment_method' => $data['payment_method'] ?? PaymentMethod::Card->value,
            'source' => $creator?->isCorporateClient() ? 'portal' : 'phone',
            'created_by' => $creator?->id,
        ]);

        // Via stops apply to the outbound leg only.
        if (! $isReturn) {
            foreach (array_values(array_filter(Arr::get($data, 'via_stops', []) ?? [])) as $i => $address) {
                $booking->stops()->create([
                    'sequence' => $i + 1,
                    'address' => $address,
                ]);
            }
        }

        return $booking;
    }
}
