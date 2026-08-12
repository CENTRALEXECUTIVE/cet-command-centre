<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Models\VehicleType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for amending an existing booking. Lighter than StoreBookingRequest:
 * no GDPR re-consent (already captured at creation) and the pickup time may be
 * in the past (an operator can correct a historical/imported job). The return
 * leg is a separate booking and is amended on its own.
 */
class UpdateBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Customer contact (updates the linked customer record).
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:32', 'required_without:customer_email'],
            'customer_email' => ['nullable', 'email', 'max:160', 'required_without:customer_phone'],

            // Service
            'vehicle_type_id' => ['required', Rule::exists('vehicle_types', 'id')],
            'airport_id' => ['nullable', Rule::exists('airports', 'id')],

            // Journey
            'pickup_at' => ['required', 'date'],
            'pickup_address' => ['required', 'string', 'max:500'],
            'destination_address' => ['required', 'string', 'max:500'],
            'via_stops' => ['nullable', 'array', 'max:10'],
            'via_stops.*' => ['nullable', 'string', 'max:500'],
            'flight_number' => ['nullable', 'string', 'max:32'],
            'passengers' => ['required', 'integer', 'min:1', 'max:16'],
            'luggage' => ['nullable', 'integer', 'min:0', 'max:30'],
            'suitcases' => ['nullable', 'integer', 'min:0', 'max:30'],
            'hand_luggage' => ['nullable', 'integer', 'min:0', 'max:30'],
            'child_seats' => ['nullable', 'integer', 'min:0', 'max:8'],
            'booster_seats' => ['nullable', 'integer', 'min:0', 'max:8'],
            'infant_seats' => ['nullable', 'integer', 'min:0', 'max:8'],
            'special_requests' => ['nullable', 'string', 'max:1000'],
            'driver_notes' => ['nullable', 'string', 'max:2000'],

            // Commercials
            'payment_method' => ['required', Rule::in(PaymentMethod::values())],
            'payment_status' => ['nullable', Rule::in(['pending', 'paid', 'refunded'])],
            'quoted_price' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'final_price' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_phone.required_without' => 'Provide a phone number or an email address for the customer.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $vehicleType = VehicleType::find($this->input('vehicle_type_id'));
            if ($vehicleType && (int) $this->input('passengers') > $vehicleType->passenger_capacity) {
                $validator->errors()->add(
                    'passengers',
                    "The {$vehicleType->name} seats up to {$vehicleType->passenger_capacity} passengers."
                );
            }
        });
    }
}
