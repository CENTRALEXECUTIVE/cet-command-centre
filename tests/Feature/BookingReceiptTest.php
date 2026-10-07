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
 * Every booking has a receipt / VAT invoice the office can view as a PDF,
 * download, or email to the customer from the booking page.
 */
class BookingReceiptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_an_admin_can_view_the_receipt_pdf(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create(['quoted_price' => 120.00]);

        $res = $this->actingAs($admin)->get(route('bookings.receipt', $booking));
        $res->assertOk();
        $res->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('inline', $res->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }

    public function test_download_sets_an_attachment_disposition(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create(['quoted_price' => 80.00]);

        $res = $this->actingAs($admin)->get(route('bookings.receipt', ['booking' => $booking, 'download' => 1]));
        $res->assertOk();
        $this->assertStringContainsString('attachment', $res->headers->get('Content-Disposition'));
    }

    public function test_a_non_admin_cannot_see_the_receipt(): void
    {
        $client = User::factory()->corporateClient()->create();
        $booking = Booking::factory()->create(['quoted_price' => 80.00]);

        $this->actingAs($client)->get(route('bookings.receipt', $booking))->assertForbidden();
    }

    public function test_the_office_can_email_the_receipt_to_the_customer(): void
    {
        Mail::fake();
        $admin = User::factory()->admin()->create();
        $customer = Customer::factory()->create(['email' => 'rider@example.com']);
        $booking = Booking::factory()->create(['customer_id' => $customer->id, 'quoted_price' => 95.00]);

        $this->actingAs($admin)->post(route('bookings.receipt.email', $booking))
            ->assertRedirect();

        Mail::assertSent(BookingReceiptMail::class, fn ($m) => $m->hasTo('rider@example.com'));
    }

    public function test_emailing_fails_gracefully_with_no_customer_email(): void
    {
        Mail::fake();
        $admin = User::factory()->admin()->create();
        $customer = Customer::factory()->create(['email' => null]);
        $booking = Booking::factory()->create(['customer_id' => $customer->id, 'quoted_price' => 95.00]);

        $this->actingAs($admin)->post(route('bookings.receipt.email', $booking))
            ->assertRedirect()
            ->assertSessionHas('error');

        Mail::assertNothingSent();
    }

    public function test_the_booking_page_shows_the_receipt_section(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create(['quoted_price' => 120.00]);

        $this->actingAs($admin)->get(route('bookings.show', $booking))->assertOk()
            ->assertSee('View PDF', false)
            ->assertSee('Email to customer', false);
    }
}
