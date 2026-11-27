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

    public function test_the_customer_gets_a_quote_request_email_with_the_vehicle_and_full_details(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        Mail::fake();
        // Even with automatic customer confirmations switched off, a quote request gets its copy.
        \App\Models\Setting::set(\App\Services\Messaging\CustomerBookingMailer::SETTING, false, 'boolean', 'widgets');
        $rr = VehicleType::where('slug', 'rolls-royce-ghost')->first();

        $this->post(route('widget.book.store'), [
            'pickup_address' => 'Manchester Airport (MAN)', 'destination_address' => 'Sheffield S1 2HH', 'destination_postcode' => 'S1 2HH',
            'journey_type' => 'one_way', 'pickup_at' => now()->addDays(10)->format('Y-m-d\TH:i'),
            'flight_number' => 'BA1234', 'flight_landing_at' => now()->addDays(10)->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $rr->id, 'passengers' => 2,
            'customer_first_name' => 'Quote', 'customer_surname' => 'Customer',
            'customer_phone' => '07700900322', 'customer_email' => 'quote@example.com',
        ])->assertOk();
        app()->terminate();

        Mail::assertSent(\App\Mail\BookingConfirmationMail::class, function ($m) use ($rr) {
            $html = $m->render();

            return $m->hasTo('quote@example.com')
                && str_contains($m->envelope()->subject, 'quote request received')
                && str_contains($html, 'Quote request received')
                && str_contains($html, $rr->name)
                && str_contains($html, 'Manchester Airport')
                && str_contains($html, 'not a confirmed booking')
                && ! str_contains($html, 'Booking Confirmation:');
        });
    }

    public function test_once_priced_the_normal_confirmation_wording_is_used(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        $b = Booking::factory()->create(['source' => 'web', 'quoted_price' => 450, 'meta' => ['quote_request' => true]]);
        $m = new \App\Mail\BookingConfirmationMail($b, paid: false);
        $this->assertStringNotContainsString('quote request', $m->envelope()->subject);
        $this->assertStringNotContainsString('Quote request received', $m->render());
    }
}
