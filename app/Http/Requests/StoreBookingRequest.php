<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Models\CorporateAccount;
use App\Models\VehicleType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Full validation for the smart booking form. Covers passenger/customer detail,
 * journey (pickup, destination, via stops, return), flight, vehicle, corporate
 * reference + mandatory cost codes, payment method and GDPR consent.
 */
class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Admins create bookings on behalf of customers; corporate clients book
        // for their own account.
        return $this->user()?->isAdmin() || $this->user()?->isCorporateClient();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Customer / passenger
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:32', 'required_without:customer_email'],
            'customer_email' => ['nullable', 'email', 'max:160', 'required_without:customer_phone'],

            // Service
            'vehicle_type_id' => ['required', Rule::exists('vehicle_types', 'id')->where('is_active', true)],
            'airport_id' => ['nullable', Rule::exists('airports', 'id')],

            // Journey
            'journey_type' => ['required', Rule::in(['one_way', 'return'])],
            'pickup_at' => ['required', 'date', 'after:now'],
            'pickup_address' => ['required', 'string', 'max:500'],
            'pickup_postcode' => ['nullable', 'string', 'max:16'],
            'destination_address' => ['required', 'string', 'max:500'],
            'destination_postcode' => ['nullable', 'string', 'max:16'],
            'via_stops' => ['nullable', 'array', 'max:10'],
            'via_stops.*' => ['nullable', 'string', 'max:500'],
            'flight_number' => ['nullable', 'string', 'max:32'],
            'passengers' => ['required', 'integer', 'min:1', 'max:60'],
            'luggage' => ['nullable', 'integer', 'min:0', 'max:30'],
            'suitcases' => ['nullable', 'integer', 'min:0', 'max:30'],
            'hand_luggage' => ['nullable', 'integer', 'min:0', 'max:30'],
            'special_requests' => ['nullable', 'string', 'max:1000'],
            'driver_notes' => ['nullable', 'string', 'max:2000'],

            // Return leg (paired booking)
            'return_pickup_at' => ['nullable', 'required_if:journey_type,return', 'date', 'after:pickup_at'],

            // Corporate
            'corporate_account_id' => ['nullable', Rule::exists('corporate_accounts', 'id')->where('is_active', true)],
            'cost_code' => ['nullable', 'string', 'max:64'],
            'corporate_reference' => ['nullable', 'string', 'max:120'],

            // Commercials
            'payment_method' => ['required', Rule::in(PaymentMethod::values())],
            'quoted_price' => ['nullable', 'numeric', 'min:0', 'max:100000'],

            // GDPR — explicit consent at the point of collection.
            'privacy_consent' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'privacy_consent.accepted' => 'You must accept the privacy notice to submit a booking.',
            'pickup_at.after' => 'The pickup time must be in the future.',
            'return_pickup_at.after' => 'The return pickup must be after the outbound pickup.',
            'customer_phone.required_without' => 'Provide a phone number or an email address for the customer.',
        ];
    }

    /**
     * Cross-field rules: passenger capacity for the chosen vehicle, and a
     * mandatory cost code when the corporate account requires one.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // Passenger count must fit the selected vehicle type.
            $vehicleType = VehicleType::find($this->input('vehicle_type_id'));
            if ($vehicleType && (int) $this->input('passengers') > $vehicleType->passenger_capacity) {
                $validator->errors()->add(
                    'passengers',
                    "The {$vehicleType->name} seats up to {$vehicleType->passenger_capacity} passengers."
                );
            }

            // Mandatory cost code enforcement for corporate accounts.
            if ($accountId = $this->input('corporate_account_id')) {
                $account = CorporateAccount::find($accountId);
                if ($account?->cost_code_required && blank($this->input('cost_code'))) {
                    $validator->errors()->add(
                        'cost_code',
                        "A cost code is required for {$account->name} bookings."
                    );
                }
            }
        });
    }
}
