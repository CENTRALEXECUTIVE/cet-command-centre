<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Services\Payments\VatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * VAT is worked out in one place. Private fares are VAT-inclusive (broken out of
 * the price the customer paid); corporate fares are net with VAT added on top.
 */
class VatServiceTest extends TestCase
{
    use RefreshDatabase;

    private function vat(): VatService
    {
        config(['cet.vat_registered' => true, 'cet.vat_rate' => 0.20]);

        return app(VatService::class);
    }

    public function test_a_gross_price_is_broken_into_net_and_vat(): void
    {
        $b = $this->vat()->fromGross(120.0);

        $this->assertSame(100.0, $b['net']);
        $this->assertSame(20.0, $b['vat']);
        $this->assertSame(120.0, $b['gross']);
    }

    public function test_a_net_price_has_vat_added_on_top(): void
    {
        $b = $this->vat()->fromNet(100.0);

        $this->assertSame(100.0, $b['net']);
        $this->assertSame(20.0, $b['vat']);
        $this->assertSame(120.0, $b['gross']);
    }

    public function test_no_vat_when_not_registered(): void
    {
        config(['cet.vat_registered' => false]);
        $b = app(VatService::class)->fromGross(120.0);

        $this->assertSame(0.0, $b['vat']);
        $this->assertSame(120.0, $b['net']);
        $this->assertSame(0.0, $b['rate']);
    }

    public function test_a_private_booking_fare_is_treated_as_vat_inclusive(): void
    {
        $this->vat();
        $booking = Booking::factory()->create([
            'payment_method' => PaymentMethod::Card->value,
            'quoted_price' => 290,
            'final_price' => null,
        ]);

        $b = $booking->fareVatBreakdown();
        $this->assertSame(290.0, $b['gross']);
        $this->assertSame(241.67, $b['net']);
        $this->assertSame(48.33, $b['vat']);
    }

    public function test_a_corporate_booking_fare_has_vat_added_on_top(): void
    {
        $this->vat();
        $booking = Booking::factory()->create([
            'payment_method' => PaymentMethod::Account->value,
            'quoted_price' => 100,
        ]);

        $b = $booking->fareVatBreakdown();
        $this->assertSame(100.0, $b['net']);
        $this->assertSame(20.0, $b['vat']);
        $this->assertSame(120.0, $b['gross']);
    }
}
