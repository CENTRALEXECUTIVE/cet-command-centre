<?php

namespace Tests\Feature;

use App\Mail\BookingConfirmationMail;
use App\Mail\OfficeBookingMail;
use App\Models\Booking;
use App\Models\VehicleType;
use App\Services\BookingStatusService;
use App\Services\Payments\PaymentGateway;
use Database\Seeders\AirportSeeder;
use Database\Seeders\DirectorSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

/**
 * The complete go-live walk: a customer books on the public site, is sent to pay,
 * pays, and the provider webhook confirms everything — status, driver allocation,
 * calendar, and BOTH confirmation emails (customer + office) with the invoice PDF
 * attached. Plus the price-on-request enquiry fork. One test that proves the whole
 * chain holds together, not just the pieces.
 */
class GoLiveEndToEndTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([VehicleTypeSeeder::class, DirectorSeeder::class, AirportSeeder::class]);
        config([
            'cet.vat_registered' => true,
            'cet.vat_rate' => 0.20,
            'cet.ops_email' => 'office@example.com',
        ]);
    }

    /** Pretend a card provider is live and hands back a hosted checkout URL. */
    private function fakeGatewayReturning(string $url): void
    {
        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('enabled')->andReturn(true);
        $gateway->shouldReceive('enabledForBooking')->andReturn(true);
        $gateway->shouldReceive('createCheckoutUrl')->andReturn($url);
        $this->instance(PaymentGateway::class, $gateway);
    }

    public function test_a_customer_books_pays_and_everything_confirms_end_to_end(): void
    {
        Mail::fake();
        $this->fakeGatewayReturning('https://pay.test/checkout/abc');
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();

        // 1) The booking page loads.
        $this->get(route('public.book'))->assertOk()->assertSee('Executive');

        // 2) Instant prices come back for the journey (extras excluded here, by design).
        $this->postJson(route('public.book.quotes'), [
            'pickup' => 'Sheffield S1 2HH', 'destination' => 'Manchester Airport (MAN)',
        ])->assertOk()->assertJsonPath('options.0.poa', false);

        // 3) The customer submits with extras → booking is created and they are sent
        //    STRAIGHT to the payment page (not the thanks page).
        $this->post(route('public.book.store'), [
            'pickup_address' => '10 Division Street, Sheffield', 'pickup_postcode' => 'S1 2HH',
            'destination_address' => 'Manchester Airport (MAN)',
            'pickup_at' => Carbon::now()->addDay()->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $exec->id, 'passengers' => 2, 'suitcases' => 1, 'hand_luggage' => 1,
            'customer_name' => 'Jane Traveller', 'customer_email' => 'jane@example.com',
            'customer_phone' => '07700900123',
            'meet_greet' => 1, 'child_seats' => 1, // base 105 + 10 + 10 = 125
        ])->assertRedirect('https://pay.test/checkout/abc');

        $booking = Booking::latest('id')->firstOrFail();
        $this->assertSame('web', $booking->source);
        $this->assertSame('pending', $booking->payment_status);
        $this->assertSame(125.0, (float) $booking->quoted_price); // extras folded into the fare
        $this->assertSame(20.0, (float) $booking->meta['fare_extras_total']);

        // The office gets the "new booking" notice straight away (unpaid = incomplete).
        Mail::assertSent(OfficeBookingMail::class, fn ($m) => $m->stage === 'new'
            && $m->booking->id === $booking->id);

        // 4) The provider webhook lands: the payment is recorded and onFarePaid runs
        //    (exactly what WebhookController::stripe/square do).
        $booking->markFarePaid('sq_live_1', (float) $booking->quoted_price);
        app(BookingStatusService::class)->onFarePaid($booking->fresh());
        $booking->refresh();

        // 5a) Status healed to paid.
        $this->assertTrue($booking->fareIsPaid());
        $this->assertSame('paid', $booking->payment_status);

        // 5b) A rotation driver was auto-allocated (executive saloon).
        $this->assertNotNull($booking->driver_id);

        // 5c) The calendar event was built in our mirror.
        $this->assertDatabaseHas('calendar_events', ['booking_id' => $booking->id]);

        // 5d) The CUSTOMER gets the paid confirmation WITH the invoice PDF attached.
        Mail::assertSent(BookingConfirmationMail::class, function ($m) use ($booking) {
            return $m->paid === true
                && $m->hasTo('jane@example.com')
                && count($m->attachments()) === 1;
        });

        // 5e) The OFFICE gets the "payment received" email WITH the invoice attached.
        Mail::assertSent(OfficeBookingMail::class, function ($m) {
            return $m->stage === 'paid'
                && $m->hasTo('office@example.com')
                && count($m->attachments()) === 1;
        });
    }

    public function test_a_price_on_request_journey_becomes_an_office_enquiry_not_a_payment(): void
    {
        Mail::fake();
        // No gateway configured (default) → price-on-request / pay-unavailable path.
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();

        $this->post(route('public.book.store'), [
            'pickup_address' => '10 Division Street, Sheffield', 'pickup_postcode' => 'S1 2HH',
            'destination_address' => 'Manchester Airport (MAN)',
            'pickup_at' => Carbon::now()->addDay()->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $exec->id, 'passengers' => 2,
            'customer_name' => 'Ed Enquiry', 'customer_email' => 'ed@example.com',
            'customer_phone' => '07700900124',
        ])->assertRedirect(route('public.book.thanks'));

        $booking = Booking::latest('id')->firstOrFail();
        $this->assertSame('pending', $booking->payment_status);

        // The customer gets an (unpaid) confirmation, the office gets the new-booking
        // notice — and nothing is marked paid.
        Mail::assertSent(BookingConfirmationMail::class, fn ($m) => $m->paid === false);
        Mail::assertSent(OfficeBookingMail::class, fn ($m) => $m->stage === 'new');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
