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

    private function resolveCustomer(array $data): Customer
    {
        $phone = $data['customer_phone'] ?? null;
        $email = $data['customer_email'] ?? null;

        // "Remember every customer": match on phone or email before creating.
        $customer = Customer::query()
            ->when($phone, fn ($q) => $q->orWhere('phone', $phone))
            ->when($email, fn ($q) => $q->orWhere('email', $email))
            ->first();

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

    private function buildLeg(array $data, Customer $customer, ?User $creator, bool $isReturn): Booking
    {
        $vehicleType = VehicleType::findOrFail($data['vehicle_type_id']);

        $pickupAt = $isReturn ? $data['return_pickup_at'] : $data['pickup_at'];
        $pickupAddress = $isReturn ? $data['destination_address'] : $data['pickup_address'];
        $destinationAddress = $isReturn ? $data['pickup_address'] : $data['destination_address'];

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
            'luggage' => $data['luggage'] ?? 0,
            'special_requests' => $data['special_requests'] ?? null,
            'status' => BookingStatus::Pending,
            'quoted_price' => $data['quoted_price'] ?? null,
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
