<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FixWebCashCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function webCard(array $attrs = []): Booking
    {
        return Booking::factory()
            ->forVehicleType(VehicleType::where('slug', 'executive')->first())
            ->create(array_merge([
                'source' => 'web',
                'payment_method' => 'card',
                'payment_status' => 'pending',
                'status' => 'pending',
                'pickup_address' => 'Sheffield S1 2HH',
                'destination_address' => 'Manchester Airport',
            ], $attrs));
    }

    public function test_it_switches_unpaid_web_card_bookings_to_cash(): void
    {
        $b = $this->webCard();

        $this->artisan('cet:fix-web-cash --apply')->assertSuccessful();

        $this->assertSame('cash', $b->fresh()->payment_method?->value);
    }

    public function test_report_only_by_default_changes_nothing(): void
    {
        $b = $this->webCard();

        $this->artisan('cet:fix-web-cash')->assertSuccessful();

        $this->assertSame('card', $b->fresh()->payment_method?->value);
    }

    public function test_it_leaves_genuine_and_paid_bookings_alone(): void
    {
        // A non-web card job (e.g. ETO import) — a real card booking.
        $eto = $this->webCard(['source' => 'eto']);
        // A web booking already paid online via Square.
        $paid = $this->webCard(['payment_status' => 'paid']);
        $paid->markFarePaid('sq_1', 100.0);
        // A cancelled web booking — settled history, left alone.
        $cancelled = $this->webCard(['status' => 'cancelled']);

        $this->artisan('cet:fix-web-cash --apply')->assertSuccessful();

        $this->assertSame('card', $eto->fresh()->payment_method?->value);
        $this->assertSame('card', $paid->fresh()->payment_method?->value);
        $this->assertSame('card', $cancelled->fresh()->payment_method?->value);
    }
}
