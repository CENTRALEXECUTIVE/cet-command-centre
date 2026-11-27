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
        $this->assertSame('critical', $ev->severity); // never muted (27 Nov 2026)
        $this->assertStringContainsString('price needed', $ev->title);
        Mail::assertSent(\App\Mail\OfficeBookingMail::class, fn ($m) => str_contains($m->envelope()->subject, 'QUOTE REQUEST'));
    }

    public function test_a_normal_website_booking_alert_is_critical_so_it_is_never_muted(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        Mail::fake();
        $this->post(route('widget.book.store'), [
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH',
            'destination_address' => 'Rotherham S60 5WG', 'destination_postcode' => 'S60 5WG',
            'pickup_at' => now()->addDays(10)->format('Y-m-d\TH:i'),
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->value('id'), 'passengers' => 1,
            'customer_first_name' => 'Alert', 'customer_surname' => 'Check',
            'customer_phone' => '07700900432', 'customer_email' => 'alert@example.com',
        ])->assertOk();
        $b = \App\Models\Booking::latest('id')->first();
        $this->assertSame('critical', WatchdogEvent::where('booking_id', $b->id)->where('event_type', 'web_booking')->value('severity'));
    }
}
