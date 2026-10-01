<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The back-office "Reviews sent" report — who's been asked for a Google review,
 * sourced from review_request messages.
 */
class ReviewsSentReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function reviewMessage(string $customerName, string $phone, string $status): Message
    {
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();
        $customer = Customer::create(['name' => $customerName, 'phone' => $phone]);
        $booking = Booking::factory()->forVehicleType($exec)->create(['customer_id' => $customer->id]);

        return $booking->messages()->create([
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'direction' => 'outbound',
            'type' => 'review_request',
            'to_address' => $phone,
            'body' => 'Please leave us a review',
            'status' => $status,
            'sent_at' => $status === 'sent' ? now() : null,
            'scheduled_for' => now(),
        ]);
    }

    public function test_the_report_shows_sent_review_requests_by_default(): void
    {
        $admin = User::factory()->admin()->create();
        $this->reviewMessage('Sent Sam', '07700900001', 'sent');
        $this->reviewMessage('Queued Quinn', '07700900002', 'queued');

        $this->actingAs($admin)->get(route('reviews-sent.index'))->assertOk()
            ->assertSee('Sent Sam')
            ->assertDontSee('Queued Quinn');
    }

    public function test_the_pending_filter_shows_queued_requests(): void
    {
        $admin = User::factory()->admin()->create();
        $this->reviewMessage('Sent Sam', '07700900001', 'sent');
        $this->reviewMessage('Queued Quinn', '07700900002', 'queued');

        $this->actingAs($admin)->get(route('reviews-sent.index', ['show' => 'pending']))->assertOk()
            ->assertSee('Queued Quinn')
            ->assertDontSee('Sent Sam');
    }

    public function test_the_all_filter_shows_both(): void
    {
        $admin = User::factory()->admin()->create();
        $this->reviewMessage('Sent Sam', '07700900001', 'sent');
        $this->reviewMessage('Queued Quinn', '07700900002', 'queued');

        $this->actingAs($admin)->get(route('reviews-sent.index', ['show' => 'all']))->assertOk()
            ->assertSee('Sent Sam')
            ->assertSee('Queued Quinn');
    }

    public function test_a_driver_cannot_see_the_report(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->get(route('reviews-sent.index'))->assertForbidden();
    }
}
