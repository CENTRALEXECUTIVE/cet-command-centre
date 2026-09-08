<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\VehicleType;
use App\Models\Voucher;
use App\Services\BookingStatusService;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The public, full-page booking funnel: instant VAT-inclusive prices for every
 * vehicle, then a booking that goes to pay in full. A business can tick "I need
 * a VAT invoice" and 20% is added on top.
 */
class PublicBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
        config(['cet.vat_registered' => true, 'cet.vat_rate' => 0.20]);
    }

    public function test_the_booking_page_loads_with_vehicles(): void
    {
        $this->get(route('public.book'))
            ->assertOk()
            ->assertSee('Book your')
            ->assertSee('Executive');
    }

    public function test_it_quotes_vat_inclusive_prices_for_every_vehicle(): void
    {
        $res = $this->postJson(route('public.book.quotes'), [
            'pickup' => 'Sheffield S1 2HH',
            'destination' => 'Manchester Airport (MAN)',
        ])->assertOk()->json('options');

        $exec = collect($res)->firstWhere('name', 'Executive');
        $this->assertSame(110, (int) $exec['price']); // new fixed rate
        $this->assertFalse($exec['poa']);
    }

    public function test_a_private_booking_is_charged_the_listed_price(): void
    {
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();

        $this->post(route('public.book.store'), $this->payload($exec->id))
            ->assertRedirect(route('public.book.thanks')); // Square off in tests → thanks

        $booking = Booking::latest('id')->first();
        $this->assertSame('web', $booking->source);
        $this->assertSame(110.0, (float) $booking->quoted_price);
        $this->assertFalse((bool) ($booking->meta['vat_invoice_requested'] ?? false));
    }

    public function test_a_business_invoice_booking_adds_20_percent_on_top(): void
    {
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();

        $this->post(route('public.book.store'), $this->payload($exec->id, ['vat_invoice' => 1]))
            ->assertRedirect(route('public.book.thanks'));

        $booking = Booking::latest('id')->first();
        $this->assertSame(132.0, (float) $booking->quoted_price); // 110 + 20%
        $this->assertTrue((bool) $booking->meta['vat_invoice_requested']);
    }

    public function test_extras_and_a_voucher_are_applied_to_the_charge(): void
    {
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();
        Voucher::create([
            'code' => 'RACHEL20', 'type' => 'percent', 'value' => 20,
            'max_uses' => 1, 'valid_from' => now()->subDay(), 'valid_to' => now()->addYear(),
        ]);

        // Base 110 + meet&greet 10 + 1 child seat 10 = 130; 20% off = 104.
        $this->post(route('public.book.store'), $this->payload($exec->id, [
            'meet_greet' => 1, 'child_seats' => 1, 'voucher' => 'rachel20',
        ]))->assertRedirect(route('public.book.thanks'));

        $booking = Booking::latest('id')->first();
        $this->assertSame(104.0, (float) $booking->quoted_price);
        $this->assertSame(26.0, (float) $booking->meta['voucher']['discount']); // 20% of 130
        $this->assertSame(0, Voucher::first()->used_count); // not consumed until paid
    }

    public function test_an_invalid_voucher_is_rejected(): void
    {
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();

        $this->post(route('public.book.store'), $this->payload($exec->id, ['voucher' => 'NOPE']))
            ->assertSessionHasErrors('voucher');
        $this->assertSame(0, Booking::count());
    }

    public function test_a_voucher_is_consumed_only_when_the_booking_is_paid(): void
    {
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();
        $voucher = Voucher::create([
            'code' => 'RACHEL20', 'type' => 'percent', 'value' => 20, 'max_uses' => 1,
            'valid_from' => now()->subDay(), 'valid_to' => now()->addYear(),
        ]);

        $this->post(route('public.book.store'), $this->payload($exec->id, ['voucher' => 'RACHEL20']))
            ->assertRedirect();
        $booking = Booking::latest('id')->first();

        // Simulate payment success → confirm.
        $booking->markFarePaid('sq_test_1', (float) $booking->quoted_price);
        app(BookingStatusService::class)->confirmPaidWebBooking($booking->fresh());

        $this->assertSame(1, $voucher->fresh()->used_count);
    }

    public function test_the_honeypot_silently_drops_bots(): void
    {
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();

        $this->post(route('public.book.store'), $this->payload($exec->id, ['company' => 'spam ltd']))
            ->assertRedirect(route('public.book'));

        $this->assertSame(0, Booking::count());
    }

    private function payload(int $vehicleTypeId, array $extra = []): array
    {
        return array_merge([
            'pickup_address' => 'Sheffield S1 2HH',
            'destination_address' => 'Manchester Airport (MAN)',
            'pickup_at' => Carbon::now()->addDay()->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $vehicleTypeId,
            'passengers' => 2,
            'suitcases' => 1,
            'hand_luggage' => 1,
            'customer_name' => 'Jane Traveller',
            'customer_email' => 'jane@example.com',
            'customer_phone' => '07700900123',
        ], $extra);
    }
}
