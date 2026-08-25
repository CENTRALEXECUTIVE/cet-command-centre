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

            $outbound = $this->buildLeg($data, $customer, $creator, isReturn: false);

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
            // Keep the customer's contact details current.
            if ($customer = $booking->customer) {
                $customer->fill(array_filter([
                    'name' => $data['customer_name'] ?? null,
                    'phone' => $data['customer_phone'] ?? null,
                    'email' => $data['customer_email'] ?? null,
                ], fn ($v) => $v !== null && $v !== ''))->save();
            }

            [$suitcases, $handLuggage, $luggage] = $this->luggageFrom($data);

            $pax = max(1, (int) ($data['passengers'] ?? 1));
            // GUARD: never store more of any seat type than there are passengers.
            $childCap = min((int) ($data['child_seats'] ?? 0), $pax);
            $boosterCap = min((int) ($data['booster_seats'] ?? 0), $pax);
            $infantCap = min((int) ($data['infant_seats'] ?? 0), $pax);
            $driverNotes = trim((string) ($data['driver_notes'] ?? '')) ?: null;
            $vehicleName = optional(VehicleType::find($data['vehicle_type_id'] ?? null))->name;

            // Work out WHICH fields the office actually changed, by comparing each
            // submitted value against what the booking currently DISPLAYS (the
            // calendar, where it's the source). Only these fields override the
            // calendar afterwards — every untouched field keeps mirroring it, so
            // an edit to one field never blanks or stales another.
            $editedFields = $this->changedFields($booking, [
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
                    'suitcases' => $suitcases,
                    'hand_luggage' => $handLuggage,
                    'child_seats' => $childCap,
                    'booster_seats' => $boosterCap,
                    'infant_seats' => $infantCap,
                    'child_seat' => ($childCap + $boosterCap + $infantCap) > 0,
                    'driver_notes' => $driverNotes,
                    // Mark the booking edited, and record exactly which fields the
                    // office changed. Untouched fields keep mirroring the calendar
                    // (the source of truth); only edited fields win over it.
                    'manually_edited_at' => now()->toIso8601String(),
                    'edited_fields' => $editedFields,
                ]),
                'special_requests' => $data['special_requests'] ?? null,
                'payment_method' => $data['payment_method'],
                'payment_status' => $data['payment_status'] ?? $booking->payment_status,
                'quoted_price' => $data['quoted_price'] ?? null,
                'final_price' => $data['final_price'] ?? null,
            ])->save();

            // Re-sync via stops (outbound legs only) from the submitted list.
            if (! $booking->is_return_leg) {
                $booking->stops()->delete();
                foreach (array_values(array_filter(Arr::get($data, 'via_stops', []) ?? [])) as $i => $address) {
                    $booking->stops()->create(['sequence' => $i + 1, 'address' => $address]);
                }
            }

            // Deliberately DO NOT touch the calendar on an edit. New bookings are
            // auto-added, but edits are the operator's to make on the calendar by
            // hand — the system never pushes an amendment to Google.

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

        $pickupAt = $isReturn ? $data['return_pickup_at'] : $data['pickup_at'];
        $pickupAddress = $isReturn ? $data['destination_address'] : $data['pickup_address'];
        $destinationAddress = $isReturn ? $data['pickup_address'] : $data['destination_address'];

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
            ]),
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
