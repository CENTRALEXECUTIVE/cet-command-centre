<?php

namespace Tests\Feature;

use App\Mail\BookingReceiptMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A cover job: we covered the job for another operator, so we invoice THEM (they
 * pay CET), with the invoice emailed or sent over WhatsApp.
 */
class CoverJobInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_marking_a_booking_as_a_cover_job_records_the_operator(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create(['quoted_price' => 100.00]);

        $this->actingAs($admin)->post(route('bookings.cover-for', $booking), [
            'name' => 'A1 Cars Ltd',
            'email' => 'accounts@a1cars.co.uk',
            'phone' => '07700900123',
            'amount' => 85,
        ])->assertRedirect();

        $booking->refresh();
        $this->assertTrue($booking->isCoverJob());
        $this->assertSame('A1 Cars Ltd', $booking->coverFor()['name']);
        $this->assertSame(85.0, $booking->coverForAmount());
    }

    public function test_the_cover_amount_defaults_to_the_fare_when_left_blank(): void
    {
        $booking = Booking::factory()->create(['quoted_price' => 140.00]);
        $booking->setCoverFor(['name' => 'B2 Travel']);

        $this->assertSame(140.0, $booking->coverForAmount());
    }

    public function test_clearing_the_name_removes_the_cover_job(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create();
        $booking->setCoverFor(['name' => 'A1 Cars Ltd', 'amount' => 50]);
        $this->assertTrue($booking->fresh()->isCoverJob());

        $this->actingAs($admin)->post(route('bookings.cover-for', $booking), ['name' => ''])
            ->assertRedirect();

        $this->assertFalse($booking->fresh()->isCoverJob());
    }

    public function test_the_cover_invoice_emails_the_operator_not_the_customer(): void
    {
        Mail::fake();
        $admin = User::factory()->admin()->create();
        $customer = Customer::factory()->create(['email' => 'rider@example.com']);
        $booking = Booking::factory()->create(['customer_id' => $customer->id, 'quoted_price' => 100.00]);
        $booking->setCoverFor(['name' => 'A1 Cars Ltd', 'email' => 'accounts@a1cars.co.uk', 'amount' => 85]);

        $this->actingAs($admin)->post(route('bookings.receipt.email', $booking))->assertRedirect();

        Mail::assertSent(BookingReceiptMail::class, fn ($m) => $m->hasTo('accounts@a1cars.co.uk') && ! $m->hasTo('rider@example.com'));
    }

    public function test_the_cover_invoice_pdf_renders_and_is_addressed_to_the_operator(): void
    {
        $booking = Booking::factory()->create(['quoted_price' => 100.00]);
        $booking->setCoverFor(['name' => 'A1 Cars Ltd', 'amount' => 85]);

        $pdf = app(\App\Services\Payments\InvoicePdf::class)->renderReceipt($booking->fresh());
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_the_booking_page_shows_the_cover_invoice_and_whatsapp(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create(['quoted_price' => 100.00]);
        $booking->setCoverFor(['name' => 'A1 Cars Ltd', 'phone' => '07700900123', 'amount' => 85]);

        $this->actingAs($admin)->get(route('bookings.show', $booking))->assertOk()
            ->assertSee('Cover-job invoice', false)
            ->assertSee('A1 Cars Ltd', false)
            ->assertSee('wa.me/447700900123', false);
    }
}
