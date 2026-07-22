<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use App\Services\BookingStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Review requests for return journeys: ONE review for the whole trip, only
 * after BOTH legs are completed. One-way jobs keep the existing behaviour.
 */
class ReviewPairingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-15 12:00:00'); // daytime, inside the send window
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function returnPair(): array
    {
        $customer = Customer::create(['name' => 'Pair Customer', 'phone' => '07700900321']);
        $outbound = Booking::factory()->create([
            'customer_id' => $customer->id, 'journey_type' => 'return',
            'is_return_leg' => false, 'status' => BookingStatus::Collected,
            'pickup_at' => now()->subDay(),
        ]);
        $return = Booking::factory()->create([
            'customer_id' => $customer->id, 'journey_type' => 'return',
            'is_return_leg' => true, 'linked_booking_id' => $outbound->id,
            'status' => BookingStatus::Collected, 'pickup_at' => now()->addDay(),
        ]);

        return [$outbound, $return];
    }

    public function test_no_review_after_only_the_outbound_leg_completes(): void
    {
        $admin = User::factory()->admin()->create();
        [$outbound, $return] = $this->returnPair();

        app(BookingStatusService::class)->transition($outbound, BookingStatus::Complete, $admin);

        $this->assertSame(0, Message::where('type', 'review_request')
            ->whereIn('booking_id', [$outbound->id, $return->id])->count(),
            'No review should be asked for while the return leg is still to run.');
    }

    public function test_one_review_for_the_pair_once_both_legs_complete(): void
    {
        $admin = User::factory()->admin()->create();
        [$outbound, $return] = $this->returnPair();
        $status = app(BookingStatusService::class);

        $status->transition($outbound, BookingStatus::Complete, $admin);
        $status->transition($return, BookingStatus::Complete, $admin);

        // Exactly ONE review request across the whole trip.
        $this->assertSame(1, Message::where('type', 'review_request')
            ->whereIn('booking_id', [$outbound->id, $return->id])->count());
    }

    public function test_a_repeat_customer_is_not_asked_for_a_review_twice(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = Customer::create(['name' => 'Repeat Customer', 'phone' => '07700900777']);
        $status = app(BookingStatusService::class);

        $first = Booking::factory()->create([
            'customer_id' => $customer->id, 'journey_type' => 'one_way',
            'status' => BookingStatus::Collected, 'pickup_at' => now()->subDay(),
        ]);
        $status->transition($first, BookingStatus::Complete, $admin);

        // They book again and that trip completes too.
        $second = Booking::factory()->create([
            'customer_id' => $customer->id, 'journey_type' => 'one_way',
            'status' => BookingStatus::Collected, 'pickup_at' => now()->subHour(),
        ]);
        $status->transition($second, BookingStatus::Complete, $admin);

        // Only the FIRST booking gets a review request — no point asking twice.
        $this->assertSame(1, Message::where('type', 'review_request')->where('booking_id', $first->id)->count());
        $this->assertSame(0, Message::where('type', 'review_request')->where('booking_id', $second->id)->count());
    }

    public function test_a_repeat_customer_on_a_separate_record_is_not_asked_again(): void
    {
        $admin = User::factory()->admin()->create();
        $status = app(BookingStatusService::class);

        // First visit — customer record A, number in 0-prefixed form.
        $custA = Customer::create(['name' => 'Same Person', 'phone' => '07700900888']);
        $first = Booking::factory()->create([
            'customer_id' => $custA->id, 'journey_type' => 'one_way',
            'status' => BookingStatus::Collected, 'pickup_at' => now()->subDay(),
        ]);
        $status->transition($first, BookingStatus::Complete, $admin);
        $this->assertSame(1, Message::where('type', 'review_request')->where('booking_id', $first->id)->count());

        // They book again but land as a SEPARATE customer record with the SAME
        // number in +44 form (what a calendar import can create).
        $custB = Customer::create(['name' => 'Same Person', 'phone' => '+447700900888']);
        $second = Booking::factory()->create([
            'customer_id' => $custB->id, 'journey_type' => 'one_way',
            'status' => BookingStatus::Collected, 'pickup_at' => now()->subHour(),
        ]);
        $status->transition($second, BookingStatus::Complete, $admin);

        // Matched by phone number → no second review ask.
        $this->assertSame(0, Message::where('type', 'review_request')->where('booking_id', $second->id)->count());
    }

    public function test_one_way_jobs_still_get_their_review(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = Customer::create(['name' => 'Solo Customer', 'phone' => '07700900654']);
        $booking = Booking::factory()->create([
            'customer_id' => $customer->id, 'journey_type' => 'one_way',
            'status' => BookingStatus::Collected, 'pickup_at' => now()->subHour(),
        ]);

        app(BookingStatusService::class)->transition($booking, BookingStatus::Complete, $admin);

        $this->assertSame(1, Message::where('type', 'review_request')->where('booking_id', $booking->id)->count());
    }
}
