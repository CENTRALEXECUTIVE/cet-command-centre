<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\VehicleType;
use App\Models\WatchdogEvent;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** 26 Nov 2026: a JELD-WEN quote request was missed. It must be loud and reach admin@. */
class QuoteRequestNotMissedTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_quote_request_is_a_sticky_alert_with_a_clear_email_subject(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        config(['cet.ops_email' => 'admin@example.com']);
        Mail::fake();
        $rr = VehicleType::where('slug', 'rolls-royce-ghost')->first() ?? VehicleType::where('name', 'like', '%Rolls%')->first();
        $this->assertNotNull($rr, 'needs a price-on-request vehicle');

        $this->post(route('widget.book.store'), [
            'pickup_address' => 'Manchester Airport (MAN)', 'destination_address' => 'Sheffield S1 2HH', 'destination_postcode' => 'S1 2HH',
            'journey_type' => 'one_way', 'pickup_at' => now()->addDays(10)->format('Y-m-d\TH:i'),
            'flight_number' => 'BA1234', 'flight_landing_at' => now()->addDays(10)->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $rr->id, 'passengers' => 1,
            'customer_first_name' => 'Claire', 'customer_surname' => 'Green',
            'customer_phone' => '07700900321', 'customer_email' => 'claire@example.com',
        ])->assertOk();

        $b = Booking::latest('id')->first();
        $this->assertNotEmpty($b->meta['quote_request'] ?? null);
        $ev = WatchdogEvent::where('booking_id', $b->id)->where('event_type', 'web_booking')->first();
        $this->assertSame('warning', $ev->severity);
        $this->assertStringContainsString('price needed', $ev->title);
        Mail::assertSent(\App\Mail\OfficeBookingMail::class, fn ($m) => str_contains($m->envelope()->subject, 'QUOTE REQUEST'));
    }
}
