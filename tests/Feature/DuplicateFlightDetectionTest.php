<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Duplicate detection by flight: the same flight for the same customer entered
 * twice (even on different days / as a mis-paired a/b) is flagged, while a genuine
 * return — a DIFFERENT flight each way — is not.
 */
class DuplicateFlightDetectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function booking(array $o): Booking
    {
        return Booking::factory()->forVehicleType(VehicleType::where('slug', 'executive')->first())->create(array_merge([
            'customer_id' => Customer::firstOrCreate(['name' => 'Janine Neill'])->id,
            'status' => 'pending',
        ], $o));
    }

    public function test_same_flight_same_customer_is_flagged_as_duplicate(): void
    {
        $arrival = $this->booking([
            'flight_number' => 'LM0693', 'pickup_at' => now()->addDays(5)->setTime(9, 15),
            'pickup_address' => 'Terminal 2, Manchester', 'destination_address' => '2 Worrygoose Lane, Rotherham',
        ]);
        // The same flight entered again (the live 9Y5MDRa/b mix-up), 20 min apart.
        $dupe = $this->booking([
            'flight_number' => 'LM0693', 'pickup_at' => now()->addDays(5)->setTime(8, 55),
            'pickup_address' => 'Manchester Airport (MAN), Terminal 2', 'destination_address' => '2 Worrygoose Lane, Rotherham',
        ]);

        $this->assertTrue($arrival->duplicateCandidates()->contains('id', $dupe->id));
    }

    public function test_a_genuine_return_with_a_different_flight_is_not_flagged(): void
    {
        $out = $this->booking([
            'flight_number' => 'LM0693', 'pickup_at' => now()->addDays(5)->setTime(9, 15),
            'pickup_address' => 'Terminal 2, Manchester', 'destination_address' => '2 Worrygoose Lane, Rotherham',
        ]);
        $return = $this->booking([
            'flight_number' => 'LM0694', 'pickup_at' => now()->addDays(9)->setTime(9, 0),
            'pickup_address' => '2 Worrygoose Lane, Rotherham', 'destination_address' => 'Terminal 2, Manchester',
        ]);

        $this->assertFalse($out->duplicateCandidates()->contains('id', $return->id));
        $this->assertFalse($return->duplicateCandidates()->contains('id', $out->id));
    }
}
