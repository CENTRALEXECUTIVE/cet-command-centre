<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 13 Nov 2026: during a Microsoft outage the office email hung, "Book & pay" looked dead,
 * and a customer's repeated clicks created six identical return trips. A repeat submit
 * must return the SAME booking, never a new one. Also: the office can download the
 * customer confirmation as a PDF to send on WhatsApp when email isn't getting through.
 */
class WebBookingDuplicateAndConfirmationPdfTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $extra = []): array
    {
        return array_merge([
            'pickup_address' => 'Sheffield Station, Sheffield S1 2BP', 'pickup_postcode' => 'S1 2BP',
            'destination_address' => 'Cowley Way, Ecclesfield S35 9ZQ',
            'pickup_at' => now()->addDays(3)->setTime(10, 0)->format('Y-m-d\TH:i'),
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->first()->id,
            'passengers' => 1,
            'customer_name' => 'Jess Example', 'customer_phone' => '07700900777',
        ], $extra);
    }

    public function test_the_same_form_submitted_twice_creates_one_booking(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        $data = $this->payload(['submission_token' => 'tok-abc-123']);

        $this->post(route('widget.book.store'), $data)->assertOk();
        $this->post(route('widget.book.store'), $data)->assertOk()->assertSee('Booking request received');

        $this->assertSame(1, Booking::where('source', 'web')->count());
    }

    public function test_a_repeat_without_a_token_is_still_caught_by_its_details(): void
    {
        $this->seed(VehicleTypeSeeder::class);

        $this->post(route('widget.book.store'), $this->payload())->assertOk();
        $this->post(route('widget.book.store'), $this->payload())->assertOk();

        $this->assertSame(1, Booking::where('source', 'web')->count());
    }

    public function test_a_genuinely_different_journey_is_still_booked(): void
    {
        $this->seed(VehicleTypeSeeder::class);

        $this->post(route('widget.book.store'), $this->payload())->assertOk();
        $this->post(route('widget.book.store'), $this->payload([
            'pickup_at' => now()->addDays(4)->setTime(10, 0)->format('Y-m-d\TH:i'),
        ]))->assertOk();

        $this->assertSame(2, Booking::where('source', 'web')->count());
    }

    public function test_the_customer_still_reaches_the_next_page_when_email_is_down(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        \Illuminate\Support\Facades\Mail::shouldReceive('to')->andThrow(new \RuntimeException('Microsoft is down'));

        $this->post(route('widget.book.store'), $this->payload(['customer_email' => 'jess@example.com']))
            ->assertOk()->assertSee('Booking request received');

        $this->assertSame(1, Booking::where('source', 'web')->count());
    }

    public function test_the_booking_form_locks_after_the_first_click(): void
    {
        $this->get(route('widget.book'))->assertOk()
            ->assertSee('name="submission_token"', false)
            ->assertSee('Booking… please wait');
    }

    public function test_admin_can_download_the_confirmation_as_a_pdf(): void
    {
        $customer = Customer::create(['name' => 'Pdf Person', 'email' => 'pdf@example.com', 'phone' => '07700900611']);
        $booking = Booking::factory()->create([
            'customer_id' => $customer->id, 'source' => 'phone', 'payment_method' => 'card',
            'payment_status' => 'paid', 'quoted_price' => 120, 'pickup_at' => now()->addDays(2),
            'status' => BookingStatus::Pending,
        ]);

        $res = $this->actingAs(User::factory()->admin()->create())
            ->get(route('bookings.confirmation-email.download', $booking))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', $res->getContent());
        $this->assertStringContainsString('attachment; filename="CET-booking-', $res->headers->get('Content-Disposition'));
    }

    public function test_a_driver_cannot_download_a_customer_confirmation(): void
    {
        $customer = Customer::create(['name' => 'Pdf Person', 'phone' => '07700900612']);
        $booking = Booking::factory()->create(['customer_id' => $customer->id, 'pickup_at' => now()->addDay()]);

        $this->actingAs(User::factory()->create(['role' => 'driver']))
            ->get(route('bookings.confirmation-email.download', $booking))
            ->assertForbidden();
    }
}
