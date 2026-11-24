<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Directors (24 Nov 2026): unpaid card/quote web bookings stay off the jobs list until paid; cash is allowed. */
class UnpaidWebBookingsOffJobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_paid_or_cash_web_bookings_are_jobs(): void
    {
        $admin = User::factory()->admin()->create();
        $day = now()->addDays(2)->setTime(10, 0);
        $make = fn (array $a) => Booking::factory()->create(array_merge(['pickup_at' => $day, 'status' => BookingStatus::Pending], $a));

        $unpaidCard = $make(['source' => 'web', 'payment_method' => 'card', 'payment_status' => 'pending']);
        $quote = $make(['source' => 'web', 'payment_method' => 'card', 'payment_status' => 'unpaid', 'meta' => ['quote_request' => true]]);
        $paidCard = $make(['source' => 'web', 'payment_method' => 'card', 'payment_status' => 'paid']);
        $cash = $make(['source' => 'web', 'payment_method' => 'cash', 'payment_status' => 'pending']);
        $office = $make(['source' => 'phone', 'payment_method' => 'card', 'payment_status' => 'pending']);

        $res = $this->actingAs($admin)->get(route('jobs.day', ['date' => $day->toDateString()]))->assertOk();
        $refs = collect($res->viewData('jobs'))->pluck('ref');
        $ref = fn ($b) => $b->external_reference ?? $b->reference;

        $this->assertFalse($refs->contains($ref($unpaidCard)));
        $this->assertFalse($refs->contains($ref($quote)));
        $this->assertTrue($refs->contains($ref($paidCard)));
        $this->assertTrue($refs->contains($ref($cash)));
        $this->assertTrue($refs->contains($ref($office)));
        $this->assertCount(2, $res->viewData('awaitingPayment'));
        $res->assertSee('2 website bookings awaiting payment');
    }
}
