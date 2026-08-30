<?php

namespace Tests\Feature;

use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public, embeddable mini web-booking widget (ETO-style): a customer checks a
 * price with no login, using the existing CET fixed-price/free-roam engine.
 */
class BookingWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_the_mini_widget_page_is_public_and_embeddable(): void
    {
        $res = $this->get(route('widget.mini'))->assertOk()
            ->assertSee('Get my price')
            ->assertSee('CENTRAL');

        // It must allow embedding on the marketing site (frame-ancestors set,
        // and no blanket X-Frame-Options DENY).
        $csp = $res->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('frame-ancestors', (string) $csp);
        $this->assertStringContainsString('centralexecutivetransfers.co.uk', (string) $csp);
        $this->assertNotSame('DENY', $res->headers->get('X-Frame-Options'));
    }

    public function test_it_returns_a_fixed_price_for_a_known_route(): void
    {
        $executive = VehicleType::where('slug', 'executive')->first();

        $this->postJson(route('widget.price'), [
            'pickup' => 'Sheffield S1 2HH',
            'destination' => 'Manchester Airport',
            'vehicle_type_id' => $executive->id,
        ])->assertOk()
            ->assertJson(['fixed' => true, 'vehicle' => $executive->name])
            ->assertJsonPath('price', 100)
            ->assertJsonPath('formatted', '£100');
    }

    public function test_it_validates_the_inputs(): void
    {
        $this->postJson(route('widget.price'), ['pickup' => 'Sheffield'])
            ->assertStatus(422);
    }

    public function test_the_full_booking_page_is_public(): void
    {
        $this->get(route('widget.book'))->assertOk()
            ->assertSee('Request booking')
            ->assertSee('No payment is taken now');
    }

    public function test_a_web_booking_lands_as_a_pending_request_and_alerts_the_office(): void
    {
        $executive = VehicleType::where('slug', 'executive')->first();

        $res = $this->post(route('widget.book.store'), [
            'pickup_address' => 'Sheffield S1 2HH',
            'destination_address' => 'Manchester Airport',
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $executive->id,
            'passengers' => 2,
            'suitcases' => 1, 'hand_luggage' => 2,
            'customer_name' => 'Lloyd Oyefuwa',
            'customer_phone' => '07464905385',
            'notes' => 'Please call on arrival',
        ])->assertOk();

        $res->assertSee('Booking request received');

        $booking = \App\Models\Booking::firstWhere('source', 'web');
        $this->assertNotNull($booking);
        $this->assertSame(\App\Enums\BookingStatus::Pending, $booking->status);
        $this->assertSame('Manchester Airport', $booking->destination_address);
        $this->assertSame(2, (int) $booking->passengers);
        $this->assertSame('Lloyd Oyefuwa', $booking->customer->name);
        // Nothing auto-sent, calendar untouched — but the office is alerted.
        $this->assertDatabaseHas('watchdog_events', [
            'booking_id' => $booking->id, 'event_type' => 'web_booking',
        ]);
    }

    public function test_the_honeypot_blocks_spam_bookings(): void
    {
        $executive = VehicleType::where('slug', 'executive')->first();

        $this->post(route('widget.book.store'), [
            'company' => 'spammer ltd', // honeypot filled
            'pickup_address' => 'A', 'destination_address' => 'B',
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $executive->id, 'passengers' => 1,
            'customer_name' => 'Bot', 'customer_phone' => '07000000000',
        ])->assertOk();

        $this->assertSame(0, \App\Models\Booking::count());
    }

    public function test_a_web_booking_requires_contact_and_a_future_time(): void
    {
        $executive = VehicleType::where('slug', 'executive')->first();

        $this->post(route('widget.book.store'), [
            'pickup_address' => 'A', 'destination_address' => 'B',
            'pickup_at' => now()->subDay()->format('Y-m-d\TH:i'), // past
            'vehicle_type_id' => $executive->id, 'passengers' => 1,
            'customer_name' => 'No Contact', // no phone or email
        ])->assertSessionHasErrors(['pickup_at', 'customer_phone']);

        $this->assertSame(0, \App\Models\Booking::count());
    }
}
