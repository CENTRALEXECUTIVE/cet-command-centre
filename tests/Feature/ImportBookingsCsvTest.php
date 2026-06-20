<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CalendarEvent;
use Database\Seeders\AirportSeeder;
use Database\Seeders\DirectorSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportBookingsCsvTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([VehicleTypeSeeder::class, DirectorSeeder::class, AirportSeeder::class]);
    }

    public function test_imports_curated_csv_with_full_details_and_drivers(): void
    {
        $this->artisan('cet:import-bookings-csv', ['path' => database_path('imports/bookings.csv')])
            ->assertSuccessful();

        // TSIMKEa: 4 passengers, real luggage, ABDI, LBA departure.
        $tsim = Booking::where('external_reference', 'TSIMKEa')->first();
        $this->assertNotNull($tsim);
        $this->assertEquals(4, $tsim->passengers);
        $this->assertEquals('2 Suitcases + 1 Carry-on', $tsim->meta['luggage_text']);
        $this->assertEquals('Abdirazak Hassan', $tsim->driver->name);

        $event = $tsim->calendarEvent;
        $this->assertStringContainsString('Rebecca Warren', $event->title);
        $this->assertStringContainsString('(ABDI)', $event->title);
        $this->assertStringContainsString('*Passengers:* 4', $event->description);
        $this->assertStringContainsString('*Luggage:* 2 Suitcases + 1 Carry-on', $event->description);

        // NU1TZ2a: cash due → 💰 on the outbound.
        $nu = Booking::where('external_reference', 'NU1TZ2a')->first();
        $this->assertStringContainsString('💰', $nu->calendarEvent->title);
        $this->assertStringContainsString('(MAJ)', $nu->calendarEvent->title);

        // RJ6CHOa: booster seat → 🚼; Minibus tag.
        $rj = Booking::where('external_reference', 'RJ6CHOa')->first();
        $this->assertStringContainsString('🚼', $rj->calendarEvent->title);
        $this->assertStringContainsString('(MINIBUS)', $rj->calendarEvent->title);

        // Stops captured (4ZXSC3 has two via stops).
        $zx = Booking::where('external_reference', '4ZXSC3')->first();
        $this->assertCount(2, $zx->meta['stops']);
        $this->assertStringContainsString('*Stop 1:*', $zx->calendarEvent->description);
        $this->assertStringContainsString('*Stop 2:*', $zx->calendarEvent->description);

        // VFMMYE legs are skipped (marked DELETED FROM CALENDAR).
        $this->assertNull(Booking::where('external_reference', 'VFMMYEa')->first());
        $this->assertNull(Booking::where('external_reference', 'VFMMYEb')->first());

        // Fully paid booking → no money emoji.
        $ch = Booking::where('external_reference', 'CH9BQQ')->first();
        $this->assertStringNotContainsString('💰', $ch->calendarEvent->title);
        $this->assertStringNotContainsString('👀', $ch->calendarEvent->title);

        // No duplicates: each reference once.
        $this->assertEquals(Booking::count(), Booking::distinct('external_reference')->count('external_reference'));
    }
}
