<?php

namespace Tests\Feature;

use App\Models\Booking;
use Database\Seeders\AirportSeeder;
use Database\Seeders\DirectorSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportIcsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([VehicleTypeSeeder::class, DirectorSeeder::class, AirportSeeder::class]);
    }

    public function test_restores_events_exactly_and_dedupes_by_reference(): void
    {
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT',
            'DTSTART:20260525T161500Z',
            'DTEND:20260525T171500Z',
            'UID:abc@google.com',
            'DESCRIPTION:📑 *Booking Confirmation – Arrival (Meet & Greet)*\n• *Customer ',
            ' Name:* Daniel Pitts\n• *Luggage:* 6 Suitcases\n• *Drop-off Location:* 92 Th',
            ' ornbridge Road\, Sheffield\n• *Booking Reference:* H9XA0Fb',
            'LOCATION:Manchester Airport M90 1QX',
            'SUMMARY:*🚼 Daniel Pitts MAN (MINIBUS)*',
            'STATUS:CONFIRMED',
            'END:VEVENT',
            // A second copy of the same reference → must dedupe to one.
            'BEGIN:VEVENT',
            'DTSTART:20260525T161500Z',
            'DTEND:20260525T171500Z',
            'UID:abc2@google.com',
            'DESCRIPTION:📑 *Booking Confirmation – Arrival*\n• *Booking Reference:* H9XA0Fb',
            'SUMMARY:*🚼 Daniel Pitts MAN (MINIBUS)*',
            'END:VEVENT',
            // An older reference-less job → kept via its UID.
            'BEGIN:VEVENT',
            'DTSTART:20260526T080000Z',
            'DTEND:20260526T090000Z',
            'UID:old99@google.com',
            'SUMMARY:*Steve Pendragon Exec (ABDI)*',
            'END:VEVENT',
            'END:VCALENDAR',
        ])."\r\n";

        $path = tempnam(sys_get_temp_dir(), 'ics').'.ics';
        file_put_contents($path, $ics);

        $this->artisan('cet:import-ics', ['path' => $path])->assertSuccessful();

        $b = Booking::where('external_reference', 'H9XA0Fb')->first();
        $this->assertNotNull($b);
        // ETO's calendar time is UK local — a 16:15 event is stored as 16:15, not
        // pushed to 17:15 by treating the 'Z' tag as UTC (BST +1h bug).
        $this->assertEquals('16:15', $b->pickup_at->format('H:i'));
        $this->assertEquals('16:15', $b->calendarEvent->start_at->format('H:i'));
        // Title + description restored verbatim (commas unescaped, lines unfolded).
        $this->assertEquals('*🚼 Daniel Pitts MAN (MINIBUS)*', $b->calendarEvent->title);
        $this->assertStringContainsString('*Customer Name:* Daniel Pitts', $b->calendarEvent->description);
        $this->assertStringContainsString('92 Thornbridge Road, Sheffield', $b->calendarEvent->description);

        // The duplicate reference collapsed to ONE booking.
        $this->assertEquals(1, Booking::where('external_reference', 'H9XA0Fb')->count());
        // The reference-less older job is kept (under an ics- key).
        $this->assertEquals(1, Booking::where('external_reference', 'like', 'ics-%')->count());

        @unlink($path);
    }
}
