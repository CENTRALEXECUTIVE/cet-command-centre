<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public customer-account widget: a customer verifies with a booking
 * reference + the contact on it, then sees and manages their own bookings.
 * Managing raises a request to the office — it never auto-acts.
 */
class CustomerAccountWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function customerBooking(): Booking
    {
        $customer = Customer::factory()->create(['name' => 'Lloyd Oyefuwa', 'email' => 'lloyd@wildlife-entertainment.com']);

        return Booking::factory()
            ->forVehicleType(VehicleType::where('slug', 'executive')->first())
            ->create(['customer_id' => $customer->id, 'pickup_at' => now()->addDays(3)]);
    }

    public function test_the_account_page_is_public_and_shows_the_lookup(): void
    {
        $this->get(route('widget.account'))->assertOk()
            ->assertSee('Manage my bookings')
            ->assertSee('Booking reference');
    }

    public function test_a_customer_verifies_by_reference_and_email_then_sees_their_bookings(): void
    {
        $booking = $this->customerBooking();

        $this->post(route('widget.account.verify'), [
            'reference' => $booking->reference,
            'contact' => 'lloyd@wildlife-entertainment.com',
        ])->assertRedirect(route('widget.account'))
            ->assertSessionHas('widget_customer_id', $booking->customer_id);

        $this->withSession(['widget_customer_id' => $booking->customer_id])
            ->get(route('widget.account'))->assertOk()
            ->assertSee('My bookings')
            ->assertSee($booking->reference);
    }

    public function test_a_wrong_contact_is_rejected(): void
    {
        $booking = $this->customerBooking();

        $this->post(route('widget.account.verify'), [
            'reference' => $booking->reference,
            'contact' => 'someone@else.com',
        ])->assertSessionMissing('widget_customer_id')
            ->assertSessionHas('account_error');
    }

    public function test_a_change_request_notifies_the_office_without_auto_acting(): void
    {
        $booking = $this->customerBooking();

        $this->withSession(['widget_customer_id' => $booking->customer_id])
            ->post(route('widget.account.request', $booking), [
                'type' => 'cancel',
                'message' => 'Please cancel, plans changed',
            ])->assertRedirect();

        // Office alerted, request recorded on the booking, status NOT changed.
        $this->assertDatabaseHas('watchdog_events', ['booking_id' => $booking->id, 'event_type' => 'web_booking']);
        $booking->refresh();
        $this->assertNotEmpty($booking->meta['customer_requests'] ?? []);
        $this->assertNotSame(\App\Enums\BookingStatus::Cancelled, $booking->status);
    }

    public function test_cannot_manage_someone_elses_booking(): void
    {
        $mine = $this->customerBooking();
        $theirs = $this->customerBooking();

        $this->withSession(['widget_customer_id' => $mine->customer_id])
            ->post(route('widget.account.request', $theirs), ['type' => 'cancel'])
            ->assertForbidden();
    }
}
