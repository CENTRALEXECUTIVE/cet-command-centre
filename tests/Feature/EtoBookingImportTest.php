<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\Import\EtoBookingImporter;
use Database\Seeders\AirportSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EtoBookingImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = [
        'Journey date', 'Passenger name', 'Reference number', 'Vehicle type', 'Status', 'Payments',
        'Total', 'Vehicle', 'Source', 'Fleet operator', 'Fleet income', 'Driver', 'Driver income',
        'Passenger charge', 'Service type', 'Service duration', 'Arrival flight number', 'Arrival time',
        'Arriving from', 'Arrival ferry name', 'Arrival ferry time', 'Arrival ferry terminal',
        'Departure flight number', 'Departure time', 'Departing to', 'Departure ferry name',
        'Departure ferry time', 'Departure ferry terminal', 'Phone number', 'Pickup', 'Dropoff', 'Via',
        'Waiting time', 'Suitcases', 'Hand luggage', 'Email', 'Meet & Greet', 'Customer', 'Departments', 'Lead passenger name',
        'Lead passenger email', 'Lead passenger phone number', 'Created at', 'Updated at', 'Currency',
        'Tracking history',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([VehicleTypeSeeder::class, AirportSeeder::class]);
    }

    /** Build a semicolon CSV from row maps keyed by column name. */
    private function csv(array $rows): string
    {
        $lines = [implode(';', array_map(fn ($h) => '"'.$h.'"', self::HEADER))];
        foreach ($rows as $row) {
            $cells = array_map(fn ($h) => '"'.($row[$h] ?? ' ').'"', self::HEADER);
            $lines[] = implode(';', $cells);
        }
        $path = tempnam(sys_get_temp_dir(), 'eto').'.csv';
        file_put_contents($path, implode("\n", $lines));

        return $path;
    }

    public function test_imports_bookings_and_skips_quotes(): void
    {
        $path = $this->csv([
            [
                'Journey date' => '24/03/2025 22:05', 'Passenger name' => 'Michael Tarulli',
                'Reference number' => 'ZWR6MM', 'Vehicle type' => 'Executive', 'Status' => 'Completed',
                'Payments' => 'Paid, Stripe, 200', 'Total' => '200.00',
                'Phone number' => '+16102120858', 'Email' => 'mt@example.com',
                'Pickup' => 'Manchester Airport (MAN), Terminal 3, Manchester, UK',
                'Dropoff' => 'Radisson Blu Hotel, Sheffield, UK',
                'Arrival flight number' => 'BA123', 'Created at' => '21/03/2025 01:44',
            ],
            [
                'Journey date' => '25/03/2025 09:00', 'Passenger name' => 'Jane Doe',
                'Reference number' => 'QUOTE1', 'Vehicle type' => '8 Seater', 'Status' => 'Request quote',
                'Total' => '0.00',
            ],
        ]);

        $stats = app(EtoBookingImporter::class)->import($path);

        $this->assertEquals(1, $stats['imported']);
        $this->assertEquals(1, $stats['skipped']);
        $this->assertEmpty($stats['errors']);

        $booking = Booking::where('external_reference', 'ZWR6MM')->first();
        $this->assertNotNull($booking);
        $this->assertEquals('eto', $booking->source_system);
        $this->assertEquals(BookingStatus::Complete, $booking->status);
        $this->assertEquals(200.00, (float) $booking->final_price);
        $this->assertEquals('executive', $booking->vehicleType->slug);
        $this->assertEquals('MAN', $booking->airport->code); // detected from "(MAN)"
        $this->assertEquals('BA123', $booking->flight_number);
        $this->assertEquals('24/03/2025', $booking->pickup_at->format('d/m/Y'));
        @unlink($path);
    }

    public function test_reimport_is_idempotent(): void
    {
        $rows = [[
            'Journey date' => '24/03/2025 22:05', 'Passenger name' => 'Michael Tarulli',
            'Reference number' => 'ZWR6MM', 'Vehicle type' => 'Executive', 'Status' => 'Completed',
            'Total' => '200.00', 'Phone number' => '+16102120858',
        ]];

        $importer = app(EtoBookingImporter::class);
        $first = $importer->import($this->csv($rows));
        $second = $importer->import($this->csv($rows));

        $this->assertEquals(1, $first['imported']);
        // Re-import updates the existing booking's financials in place — no new row.
        $this->assertEquals(0, $second['imported']);
        $this->assertEquals(1, $second['updated']);
        $this->assertEquals(1, Booking::where('external_reference', 'ZWR6MM')->count());
    }

    public function test_reimport_refreshes_financials_on_existing_booking(): void
    {
        // Booking already exists (e.g. from the calendar/live feed) with no price.
        $existing = \App\Models\Booking::create([
            'reference' => \App\Models\Booking::generateReference(),
            'external_reference' => 'ZWR6MM', 'source_system' => 'eto',
            'customer_id' => \App\Models\Customer::create(['name' => 'X', 'phone' => '07000000000'])->id,
            'vehicle_type_id' => \App\Models\VehicleType::where('slug', 'executive')->first()->id,
            'pickup_at' => now()->subDay(), 'pickup_address' => 'A', 'destination_address' => 'B',
            'passengers' => 1, 'status' => 'pending', 'payment_method' => 'card',
        ]);

        app(EtoBookingImporter::class)->import($this->csv([[
            'Journey date' => '24/03/2025 22:05', 'Reference number' => 'ZWR6MM',
            'Vehicle type' => 'Executive', 'Status' => 'Completed', 'Total' => '200.00',
            'Payments' => 'Paid, Stripe, 200',
        ]]));

        $existing->refresh();
        $this->assertEquals(200.00, (float) $existing->final_price); // financials filled
        $this->assertEquals(BookingStatus::Complete, $existing->status); // marked complete
    }

    public function test_vehicle_and_payment_mapping(): void
    {
        $path = $this->csv([[
            'Journey date' => '01/05/2025 06:00', 'Passenger name' => 'Cash Customer',
            'Reference number' => 'CASH1', 'Vehicle type' => 'Executive 8 Seater', 'Status' => 'Confirmed',
            'Payments' => 'Pending, Cash, 90', 'Total' => '90.00', 'Phone number' => '07700900001',
        ]]);

        app(EtoBookingImporter::class)->import($path);
        $booking = Booking::where('external_reference', 'CASH1')->first();

        $this->assertEquals('v-class', $booking->vehicleType->slug); // "Executive 8 Seater" = V-Class
        $this->assertEquals('cash', $booking->payment_method->value);
        $this->assertEquals('balance_remaining', $booking->payment_status);
        $this->assertEquals(BookingStatus::Pending, $booking->status); // Confirmed → pending
        @unlink($path);
    }

    public function test_bst_times_are_stored_as_uk_local_with_no_shift(): void
    {
        config(['app.timezone' => 'Europe/London']); // production timezone

        // ETO's journey time is already UK local — a summer (BST) 14:10 pickup is
        // stored as 14:10, exactly as it reads in ETO and the confirmation email.
        // (It must NOT be treated as UTC and pushed to 15:10.)
        $path = $this->csv([[
            'Journey date' => '15/07/2026 14:10', 'Passenger name' => 'BST Test',
            'Reference number' => 'BST1', 'Vehicle type' => 'Executive', 'Status' => 'Confirmed',
            'Payments' => 'Paid, Card, 100', 'Total' => '100.00', 'Phone number' => '07700900002',
        ]]);
        app(EtoBookingImporter::class)->import($path);

        $booking = Booking::where('external_reference', 'BST1')->first();
        $this->assertEquals('14:10', $booking->pickup_at->format('H:i'));
        @unlink($path);
    }

    public function test_winter_times_are_stored_as_uk_local_with_no_shift(): void
    {
        config(['app.timezone' => 'Europe/London']);

        // A winter (GMT) 09:00 pickup is stored as 09:00 — no shift either way.
        $path = $this->csv([[
            'Journey date' => '15/01/2026 09:00', 'Passenger name' => 'GMT Test',
            'Reference number' => 'GMT1', 'Vehicle type' => 'Executive', 'Status' => 'Confirmed',
            'Payments' => 'Paid, Card, 100', 'Total' => '100.00', 'Phone number' => '07700900003',
        ]]);
        app(EtoBookingImporter::class)->import($path);

        $booking = Booking::where('external_reference', 'GMT1')->first();
        $this->assertEquals('09:00', $booking->pickup_at->format('H:i'));
        @unlink($path);
    }

    public function test_suitcases_and_hand_luggage_are_stored_separately(): void
    {
        $path = $this->csv([[
            'Journey date' => '15/07/2026 14:10', 'Passenger name' => 'Luggage Test',
            'Reference number' => 'LUG1', 'Vehicle type' => 'Executive', 'Status' => 'Confirmed',
            'Payments' => 'Paid, Card, 100', 'Total' => '100.00', 'Phone number' => '07700900004',
            'Suitcases' => '2', 'Hand luggage' => '1',
        ]]);
        app(EtoBookingImporter::class)->import($path);

        $booking = Booking::where('external_reference', 'LUG1')->first();
        $this->assertEquals(2, $booking->meta['suitcases']);
        $this->assertEquals(1, $booking->meta['hand_luggage']);
        $this->assertEquals(3, $booking->luggage); // combined total kept in sync
        $this->assertEquals('2 suitcases · 1 hand luggage', $booking->luggageBreakdown());
    }
}
