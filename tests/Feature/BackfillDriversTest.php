<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CalendarEvent;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\DirectorSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillDriversTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([VehicleTypeSeeder::class, DirectorSeeder::class]);
    }

    private function bookingWithTitle(string $title, string $ref): Booking
    {
        $booking = Booking::create([
            'reference' => Booking::generateReference(),
            'external_reference' => $ref,
            'source_system' => 'eto',
            'customer_id' => Customer::create(['name' => 'C '.$ref, 'phone' => '07000000000'])->id,
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->first()->id,
            'pickup_at' => now()->addDay(),
            'pickup_address' => 'A', 'destination_address' => 'B',
            'passengers' => 1, 'status' => 'pending', 'payment_method' => 'card',
        ]);
        CalendarEvent::create([
            'booking_id' => $booking->id,
            'calendar_id' => 'admin@centralexecutivetransfers.co.uk',
            'title' => $title,
            'description' => 'x',
            'start_at' => $booking->pickup_at,
            'end_at' => $booking->pickup_at->copy()->addHour(),
            'timezone' => 'Europe/London',
            'sync_status' => 'synced',
        ]);

        return $booking;
    }

    public function test_backfills_person_tags_and_leaves_vehicle_tags_alone(): void
    {
        $abdiJob = $this->bookingWithTitle('*Rebecca Fielding MAN (ABDI)*', 'AAA1');
        $majJob = $this->bookingWithTitle('*Giles Coke LBA (MAJ)*', 'BBB2');
        $vclassJob = $this->bookingWithTitle('*Kim Moore FREE ROAM (V CLASS)*', 'CCC3');

        $this->artisan('cet:backfill-drivers')->assertSuccessful();

        $this->assertEquals('abdi@centralexecutivetransfers.co.uk', $abdiJob->fresh()->driver->email);
        $this->assertEquals('maj@centralexecutivetransfers.co.uk', $majJob->fresh()->driver->email);
        // Vehicle-only tag has no person → stays unallocated.
        $this->assertNull($vclassJob->fresh()->driver_id);
    }

    public function test_never_overwrites_an_existing_driver(): void
    {
        $maj = User::where('email', 'maj@centralexecutivetransfers.co.uk')->first();
        $job = $this->bookingWithTitle('*Rebecca Fielding MAN (ABDI)*', 'DDD4');
        $job->forceFill(['driver_id' => $maj->id])->save(); // already allocated to MAJ

        $this->artisan('cet:backfill-drivers')->assertSuccessful();

        // Untouched — the existing allocation wins over the calendar tag.
        $this->assertEquals($maj->id, $job->fresh()->driver_id);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $job = $this->bookingWithTitle('*Rebecca Fielding MAN (ABDI)*', 'EEE5');

        $this->artisan('cet:backfill-drivers', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull($job->fresh()->driver_id);
    }
}
