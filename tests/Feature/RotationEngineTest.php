<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Airport;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\RotationState;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\RotationService;
use Database\Seeders\AirportSeeder;
use Database\Seeders\DirectorSeeder;
use Database\Seeders\RotationSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RotationEngineTest extends TestCase
{
    use RefreshDatabase;

    private RotationService $rotation;

    private User $abdi;

    private User $maj;

    private VehicleType $executive;

    private VehicleType $minibus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([VehicleTypeSeeder::class, DirectorSeeder::class, AirportSeeder::class, RotationSeeder::class]);

        $this->rotation = app(RotationService::class);
        $this->abdi = User::where('email', 'abdi@centralexecutivetransfers.co.uk')->first();
        $this->maj = User::where('email', 'maj@centralexecutivetransfers.co.uk')->first();
        $this->executive = VehicleType::where('slug', 'executive')->first();
        $this->minibus = VehicleType::where('slug', 'minibus-8')->first();
    }

    private function makeBooking(VehicleType $type, ?string $airportCode, array $overrides = []): Booking
    {
        $customer = Customer::create(['name' => 'Test Passenger']);
        $airport = $airportCode ? Airport::where('code', $airportCode)->first() : null;

        return Booking::create(array_merge([
            'reference' => Booking::generateReference(),
            'customer_id' => $customer->id,
            'vehicle_type_id' => $type->id,
            'airport_id' => $airport?->id,
            'pickup_at' => now()->addDay(),
            'pickup_address' => 'Pickup',
            'destination_address' => 'Destination',
            'passengers' => 1,
            'status' => BookingStatus::Pending,
            'payment_method' => 'card',
        ], $overrides));
    }

    public function test_executive_lhr_allocates_abdi_then_advances_to_maj(): void
    {
        // Seeded: LHR next = ABDI.
        $booking = $this->makeBooking($this->executive, 'LHR');

        $driver = $this->rotation->allocate($booking);

        $this->assertTrue($driver->is($this->abdi));
        $this->assertTrue($booking->fresh()->affected_rotation);

        // Pointer has advanced to MAJ.
        $airport = Airport::where('code', 'LHR')->first();
        $state = RotationState::where('airport_id', $airport->id)
            ->where('vehicle_type_id', $this->executive->id)->first();
        $this->assertEquals($this->maj->id, $state->next_driver_id);
    }

    public function test_ema_is_next_for_maj(): void
    {
        // Seeded: EMA next = MAJ.
        $booking = $this->makeBooking($this->executive, 'EMA');

        $driver = $this->rotation->allocate($booking);

        $this->assertTrue($driver->is($this->maj));
    }

    public function test_lba_is_now_next_for_abdi(): void
    {
        // LBA moved to ABDI in the current rotation state.
        $booking = $this->makeBooking($this->executive, 'LBA');

        $driver = $this->rotation->allocate($booking);

        $this->assertTrue($driver->is($this->abdi));
    }

    public function test_man_is_now_next_for_abdi(): void
    {
        // MAN moved to ABDI in the current rotation state.
        $booking = $this->makeBooking($this->executive, 'MAN');

        $driver = $this->rotation->allocate($booking);

        $this->assertTrue($driver->is($this->abdi));
    }

    public function test_non_rotation_vehicle_does_not_allocate_or_advance(): void
    {
        $booking = $this->makeBooking($this->minibus, 'LHR');

        $driver = $this->rotation->allocate($booking);

        $this->assertNull($driver);
        $this->assertNull($booking->fresh()->driver_id);

        // Executive pointer for LHR is untouched (still ABDI).
        $airport = Airport::where('code', 'LHR')->first();
        $state = RotationState::where('airport_id', $airport->id)
            ->where('vehicle_type_id', $this->executive->id)->first();
        $this->assertEquals($this->abdi->id, $state->next_driver_id);
    }

    public function test_unknown_airport_defaults_to_abdi_first(): void
    {
        $newcastle = Airport::create(['code' => 'NCL', 'name' => 'Newcastle', 'is_active' => true]);
        $booking = $this->makeBooking($this->executive, 'NCL');

        $driver = $this->rotation->allocate($booking);

        // No rotation state existed → ABDI goes first.
        $this->assertTrue($driver->is($this->abdi));
    }

    public function test_paired_outbound_and_return_share_driver_and_advance_once(): void
    {
        $airport = Airport::where('code', 'LHR')->first();
        $outbound = $this->makeBooking($this->executive, 'LHR', ['journey_type' => 'return']);
        $return = $this->makeBooking($this->executive, 'LHR', [
            'journey_type' => 'return',
            'is_return_leg' => true,
            'linked_booking_id' => $outbound->id,
        ]);

        $outDriver = $this->rotation->allocate($outbound);
        $returnDriver = $this->rotation->allocate($return->fresh());

        // Same driver for both legs.
        $this->assertTrue($outDriver->is($this->abdi));
        $this->assertTrue($returnDriver->is($this->abdi));

        // Rotation advanced ONCE only → now MAJ.
        $state = RotationState::where('airport_id', $airport->id)
            ->where('vehicle_type_id', $this->executive->id)->first();
        $this->assertEquals($this->maj->id, $state->next_driver_id);

        // Return leg did not itself advance the pointer.
        $this->assertFalse($return->fresh()->affected_rotation);
    }

    public function test_substitution_keeps_original_rotation_position(): void
    {
        $airport = Airport::where('code', 'LHR')->first();
        $booking = $this->makeBooking($this->executive, 'LHR');
        $this->rotation->allocate($booking); // ABDI assigned, pointer → MAJ

        $stateBefore = RotationState::where('airport_id', $airport->id)
            ->where('vehicle_type_id', $this->executive->id)->first()->next_driver_id;

        // A third-party substitute covers the job.
        $sub = User::factory()->driver()->create(['name' => 'Third Party Sub']);
        $this->rotation->substitute($booking->fresh(), $sub);

        // Booking now shows the substitute…
        $this->assertEquals($sub->id, $booking->fresh()->driver_id);
        // …but the rotation pointer is unchanged.
        $stateAfter = RotationState::where('airport_id', $airport->id)
            ->where('vehicle_type_id', $this->executive->id)->first()->next_driver_id;
        $this->assertEquals($stateBefore, $stateAfter);
    }
}
