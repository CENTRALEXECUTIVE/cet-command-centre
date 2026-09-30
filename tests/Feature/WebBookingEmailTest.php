<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Mail\BookingConfirmationMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\VehicleType;
use App\Services\BookingStatusService;
use App\Services\Payments\BookingInvoicePdf;
use Database\Seeders\AirportSeeder;
use Database\Seeders\DirectorSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The customer confirmation / receipt email and its VAT invoice PDF (mirrors the
 * emails + invoice ETO produced).
 */
class WebBookingEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([VehicleTypeSeeder::class, AirportSeeder::class, DirectorSeeder::class]);
        config(['cet.vat_registered' => true, 'cet.vat_rate' => 0.20, 'cet.company.vat_number' => 'GB123456789']);
    }

    private function paidWebBooking(): Booking
    {
        $exec = VehicleType::where('affects_rotation', true)->firstOrFail();

        return Booking::factory()->create([
            'customer_id' => Customer::create(['name' => 'Jane Traveller', 'email' => 'jane@example.com', 'phone' => '07700900123'])->id,
            'vehicle_type_id' => $exec->id,
            'source' => 'web',
            'status' => BookingStatus::Pending->value,
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'quoted_price' => 300,
            'pickup_address' => '9 Harney Close, Darnall, Sheffield, S9 4AB',
            'destination_address' => 'Sheffield Station, S1 2BP',
        ]);
    }

    public function test_the_customer_gets_a_confirmation_email_when_paid(): void
    {
        Mail::fake();
        $booking = $this->paidWebBooking();

        app(BookingStatusService::class)->confirmPaidWebBooking($booking);

        Mail::assertSent(BookingConfirmationMail::class, function ($mail) use ($booking) {
            return $mail->paid === true
                && $mail->hasTo('jane@example.com')
                && $mail->booking->id === $booking->id;
        });
        $this->assertNotEmpty($booking->fresh()->meta['customer_email_sent']);
    }

    public function test_the_confirmation_is_only_emailed_once(): void
    {
        Mail::fake();
        $booking = $this->paidWebBooking();

        app(BookingStatusService::class)->confirmPaidWebBooking($booking);
        app(BookingStatusService::class)->confirmPaidWebBooking($booking->fresh());

        Mail::assertSent(BookingConfirmationMail::class, 1);
    }

    public function test_the_office_is_emailed_a_new_web_booking(): void
    {
        Mail::fake();

        $exec = VehicleType::where('slug', 'executive')->firstOrFail();
        $this->post(route('public.book.store'), [
            'pickup_address' => '10 Division Street, Sheffield',
            'pickup_postcode' => 'S1 2HH',
            'destination_address' => 'Manchester Airport (MAN)',
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $exec->id,
            'passengers' => 2,
            'customer_name' => 'Jane Traveller',
            'customer_email' => 'jane@example.com',
            'customer_phone' => '07700900123',
        ])->assertRedirect();

        Mail::assertSent(\App\Mail\OfficeBookingMail::class, fn ($m) => $m->hasTo(config('cet.ops_email')));
    }

    public function test_the_confirmation_shows_a_tip_link_when_card_tips_are_live(): void
    {
        config(['services.square.access_token' => 'tok', 'services.square.location_id' => 'loc']);
        $booking = $this->paidWebBooking();

        $html = (new BookingConfirmationMail($booking, paid: true))->render();

        $this->assertStringContainsString('Add a tip', $html);
        $this->assertStringContainsString($booking->fresh()->tipUrl(), $html);
    }

    public function test_no_tip_link_when_card_tips_are_off(): void
    {
        config(['services.square.access_token' => null, 'services.square.location_id' => null]);
        $booking = $this->paidWebBooking();

        $html = (new BookingConfirmationMail($booking, paid: true))->render();

        $this->assertStringNotContainsString('Add a tip', $html);
    }

    public function test_a_cash_booking_email_says_pay_the_driver_not_square(): void
    {
        $exec = VehicleType::where('affects_rotation', true)->firstOrFail();
        $booking = Booking::factory()->create([
            'customer_id' => Customer::create(['name' => 'Cash Cathy', 'email' => 'cathy@example.com', 'phone' => '07700900124'])->id,
            'vehicle_type_id' => $exec->id, 'source' => 'web',
            'payment_method' => 'cash', 'payment_status' => 'pending', 'quoted_price' => 105,
            'pickup_address' => 'Sheffield S1 2HH', 'destination_address' => 'Rotherham S60 1AA',
            'meta' => ['vat_invoice_requested' => false],
        ]);

        $html = (new BookingConfirmationMail($booking, paid: false))->render();

        $this->assertStringContainsString('Pay the driver on the day', $html);
        $this->assertStringNotContainsString('(Square)', $html);
        $this->assertStringNotContainsString('Pay now', $html);
        // No VAT line on a non-VAT booking.
        $this->assertStringNotContainsString('Includes VAT', $html);
    }

    public function test_a_vat_booking_email_shows_vat_and_card_payment(): void
    {
        $exec = VehicleType::where('affects_rotation', true)->firstOrFail();
        $booking = Booking::factory()->create([
            'customer_id' => Customer::create(['name' => 'VAT Val', 'email' => 'val@example.com', 'phone' => '07700900125'])->id,
            'vehicle_type_id' => $exec->id, 'source' => 'web',
            'payment_method' => 'card', 'payment_status' => 'pending', 'quoted_price' => 120,
            'pickup_address' => 'Sheffield S1 2HH', 'destination_address' => 'Leeds LS1 1AA',
            'meta' => ['vat_invoice_requested' => true],
        ]);

        $html = (new BookingConfirmationMail($booking, paid: false, payUrl: 'https://pay.example/x'))->render();

        $this->assertStringContainsString('Includes VAT', $html);
        $this->assertStringContainsString('(Card)', $html);
        $this->assertStringContainsString('Pay now', $html);
        $this->assertStringNotContainsString('Pay the driver on the day', $html);
    }

    public function test_a_card_booking_without_vat_shows_no_vat_line_and_no_markup(): void
    {
        // Not all card jobs are VAT: a card booking with no VAT invoice requested
        // shows "(Card)" but NO VAT line, and the fare is not marked up.
        $exec = VehicleType::where('affects_rotation', true)->firstOrFail();
        $booking = Booking::factory()->create([
            'customer_id' => Customer::create(['name' => 'Card Carl', 'email' => 'carl@example.com', 'phone' => '07700900126'])->id,
            'vehicle_type_id' => $exec->id, 'source' => 'web',
            'payment_method' => 'card', 'payment_status' => 'pending', 'quoted_price' => 105,
            'pickup_address' => 'Sheffield S1 2HH', 'destination_address' => 'Manchester Airport',
            'meta' => ['vat_invoice_requested' => false],
        ]);

        $this->assertFalse($booking->vatInvoiceRequested());

        $html = (new BookingConfirmationMail($booking, paid: false, payUrl: 'https://pay.example/x'))->render();

        $this->assertStringContainsString('(Card)', $html);
        $this->assertStringNotContainsString('Includes VAT', $html);
        // The fare is the plain £105 — never marked up by 20% for a non-VAT card job.
        $this->assertStringContainsString('£105.00', $html);
        $this->assertStringNotContainsString('£126.00', $html);
    }

    public function test_the_invoice_pdf_renders_with_vat(): void
    {
        $booking = $this->paidWebBooking();

        $pdf = app(BookingInvoicePdf::class)->render($booking);
        $this->assertStringStartsWith('%PDF', $pdf);

        // £300 gross → net £250, VAT £50 at 20%.
        $data = app(BookingInvoicePdf::class)->data($booking);
        $this->assertSame(250.0, $data['net']);
        $this->assertSame(50.0, $data['vatAmount']);
        $this->assertSame(300.0, $data['gross']);
        $this->assertTrue($data['paid']);
        $this->assertSame(0.0, $data['amountDue']);
        $this->assertSame('GB123456789', $data['vatNumber']);
    }
}
