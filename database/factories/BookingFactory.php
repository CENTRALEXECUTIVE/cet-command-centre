<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\VehicleType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'reference' => Booking::generateReference(),
            'customer_id' => Customer::factory(),
            'vehicle_type_id' => fn () => VehicleType::query()->value('id')
                ?? VehicleType::create([
                    'name' => 'Executive', 'slug' => 'executive-'.fake()->unique()->numerify('##'),
                    'passenger_capacity' => 4, 'affects_rotation' => true,
                ])->id,
            'journey_type' => 'one_way',
            'pickup_at' => now()->addDays(2),
            'pickup_address' => fake()->streetAddress(),
            'destination_address' => fake()->streetAddress(),
            'passengers' => fake()->numberBetween(1, 4),
            'luggage' => fake()->numberBetween(0, 3),
            'status' => BookingStatus::Pending,
            'payment_method' => PaymentMethod::Card->value,
            'source' => 'phone',
        ];
    }

    public function forVehicleType(VehicleType $type): static
    {
        return $this->state(fn () => ['vehicle_type_id' => $type->id]);
    }
}
