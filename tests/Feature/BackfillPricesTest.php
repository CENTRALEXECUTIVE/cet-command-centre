<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CalendarEvent;
use App\Models\Customer;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillPricesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function booking(array $overrides, ?string $paymentLine = null): Booking
    {
        $booking = Booking::create(array_merge([
            'reference' => Booking::generateReference(),
            'customer_id' => Customer::create(['name' => 'C', 'phone' => '07000000000'])->id,
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->first()->id,
            'pickup_at' => now()->subDay(),
            'pickup_address' => 'A', 'destination_address' => 'B',
            'passengers' => 1, 'status' => 'pending', 'payment_method' => 'card',
        ], $overrides));

        if ($paymentLine !== null) {
            CalendarEvent::create([
                'booking_id' => $booking->id,
                'calendar_id' => 'admin@centralexecutivetransfers.co.uk',
                'title' => '*Test*',
                'description' => "📑 *Booking Confirmation – Transfer*\n• *Payment:* {$paymentLine}\n• *Booking Reference:* X",
                'start_at' => $booking->pickup_at,
                'end_at' => $booking->pickup_at->copy()->addHour(),
                'timezone' => 'Europe/London', 'sync_status' => 'synced',
            ]);
        }

        return $booking;
    }

    public function test_fills_from_meta_total_amount(): void
    {
        $job = $this->booking(['meta' => ['total_amount' => 215], 'payment_status' => 'paid']);

        $this->artisan('cet:backfill-prices')->assertSuccessful();

        $this->assertEquals(215, $job->fresh()->quoted_price);
        $this->assertEquals(215, $job->fresh()->final_price); // paid → final set
    }

    public function test_fills_from_calendar_payment_line_and_sums_splits(): void
    {
        $paid = $this->booking([], 'Paid £300 (Square)');
        $split = $this->booking([], 'Paid £150 (Square) + 👀 Pending £220');

        $this->artisan('cet:backfill-prices')->assertSuccessful();

        $this->assertEquals(300, $paid->fresh()->quoted_price);
        $this->assertEquals(300, $paid->fresh()->final_price);       // fully paid
        $this->assertEquals(370, $split->fresh()->quoted_price);      // 150 + 220
        $this->assertNull($split->fresh()->final_price);              // balance pending
    }

    public function test_never_overwrites_an_existing_price(): void
    {
        $job = $this->booking(['quoted_price' => 99, 'meta' => ['total_amount' => 215]]);

        $this->artisan('cet:backfill-prices')->assertSuccessful();

        $this->assertEquals(99, $job->fresh()->quoted_price); // untouched
    }
}
