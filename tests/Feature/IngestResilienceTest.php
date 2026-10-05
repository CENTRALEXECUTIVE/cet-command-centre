<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Services\Ai\AnthropicService;
use App\Services\CalendarEventBuilder;
use App\Services\Calendar\GoogleCalendarService;
use App\Services\Inbox\EtoEmailParser;
use App\Services\Inbox\GraphMailClient;
use App\Services\Inbox\OutlookBookingService;
use App\Services\Pricing\FixedPriceService;
use App\Services\RotationService;
use Database\Seeders\AirportSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * The ETO ingest must be bullet-proof per email: one that errors can NEVER abort
 * the batch. Emails are processed oldest→newest, so a failure on an earlier email
 * used to hide a just-arrived booking (processed last) on every run.
 */
class IngestResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([AirportSeeder::class, VehicleTypeSeeder::class]);
    }

    private function body(string $ref, string $when): string
    {
        return "New booking {$ref} has been created.\n\n"
            ."Journey\n"
            ."Date & time: {$when}\n"
            ."Pickup: A Road, Chesterfield\n"
            ."Dropoff: B Road, Newark\n"
            ."Vehicle type: Executive\n"
            ."Passengers: 1\n\n"
            ."Reservation\n"
            ."Reference number: {$ref}\n"
            ."Payments: £50 (Square) - Paid\n";
    }

    public function test_a_failing_email_does_not_block_the_newest_booking(): void
    {
        // fetchRecent returns NEWEST first; the good (newest) booking is therefore
        // processed LAST, after the poison.
        $mail = Mockery::mock(GraphMailClient::class);
        $mail->shouldReceive('fetchRecent')->andReturn([
            ['subject' => 'New booking 4AHV7G', 'body' => $this->body('4AHV7G', '06/11/2026 08:45'), 'from' => 'eto@x'],
            ['subject' => 'New booking POISON1', 'body' => $this->body('POISON1', '05/11/2026 09:00'), 'from' => 'eto@x'],
        ]);

        // Real parser runs; only the upsert is intercepted so POISON1 throws.
        $svc = Mockery::mock(OutlookBookingService::class, [
            $mail, app(AnthropicService::class), app(EtoEmailParser::class), app(FixedPriceService::class),
            app(CalendarEventBuilder::class), app(GoogleCalendarService::class), app(RotationService::class),
        ])->makePartial();
        $svc->shouldReceive('upsertFromParsed')->andReturnUsing(function (array $parsed, bool $rot) {
            if (($parsed['reference'] ?? null) === 'POISON1') {
                throw new \RuntimeException('boom');
            }

            return ['booking' => new Booking(['external_reference' => $parsed['reference']]), 'action' => 'created'];
        });

        $stats = $svc->ingest(30, false);

        // The newest booking was still reached and processed despite the poison.
        $this->assertSame(1, $stats['created']);
        $this->assertGreaterThanOrEqual(1, $stats['skipped']);
    }

    public function test_ingest_creates_a_normal_new_booking_end_to_end(): void
    {
        $mail = Mockery::mock(GraphMailClient::class);
        $mail->shouldReceive('fetchRecent')->andReturn([
            ['subject' => 'New booking 4AHV7G', 'body' => $this->body('4AHV7G', '06/11/2026 08:45'), 'from' => 'eto@x'],
        ]);
        $this->instance(GraphMailClient::class, $mail);

        app(OutlookBookingService::class)->ingest(30, false);

        $this->assertDatabaseHas('bookings', ['external_reference' => '4AHV7G', 'source_system' => 'eto']);
    }
}
