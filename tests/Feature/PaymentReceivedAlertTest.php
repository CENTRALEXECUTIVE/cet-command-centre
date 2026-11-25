<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\WatchdogEvent;
use App\Services\BookingStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 25 Nov 2026: a £105 card-link payment came in and nothing appeared on the dashboard. */
class PaymentReceivedAlertTest extends TestCase
{
    use RefreshDatabase;

    private function pay(Booking $b, string $id, float $amount): void
    {
        $b->markFarePaid($id, $amount, 'chauffeurs');
        $b->save();
        app(BookingStatusService::class)->onFarePaid($b->fresh());
    }

    public function test_a_card_payment_on_an_office_booking_alerts_the_office_once(): void
    {
        $b = Booking::factory()->create(['source' => 'office', 'payment_method' => 'card', 'quoted_price' => 105, 'final_price' => 105]);
        $this->pay($b, 'cs_test_1', 105);

        $alerts = WatchdogEvent::where('event_type', 'payment_received')->get();
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('£105.00', $alerts->first()->title);
        $this->assertSame($b->id, $alerts->first()->booking_id);

        // The same payment reported again (webhook retry, booking opened) doesn't repeat it.
        app(BookingStatusService::class)->onFarePaid($b->fresh());
        $this->assertSame(1, WatchdogEvent::where('event_type', 'payment_received')->count());
    }

    public function test_an_old_payment_never_raises_an_alert(): void
    {
        $b = Booking::factory()->create(['source' => 'office', 'payment_method' => 'card', 'quoted_price' => 80, 'final_price' => 80]);
        $this->travel(-3)->days();
        $b->markFarePaid('cs_old', 80, 'chauffeurs');
        $b->save();
        $this->travelBack();
        app(BookingStatusService::class)->onFarePaid($b->fresh());
        $this->assertSame(0, WatchdogEvent::where('event_type', 'payment_received')->count());
    }
}
