<?php

namespace Tests\Feature;

use App\Mail\BookingConfirmationMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\Messaging\CustomerBookingMailer;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Automatic customer confirmation emails — and the guarantee they can NEVER
 * reach ETO / existing-website customers (only web-widget bookings, only when
 * the setting is on).
 */
class WidgetCustomerEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
        Mail::fake();
    }

    private function booking(string $source, ?string $email = 'guest@example.com'): Booking
    {
        $customer = Customer::factory()->create(['email' => $email]);

        return Booking::factory()
            ->forVehicleType(VehicleType::where('slug', 'executive')->first())
            ->create(['customer_id' => $customer->id, 'source' => $source]);
    }

    public function test_a_web_booking_is_emailed_when_the_setting_is_on(): void
    {
        Setting::set(CustomerBookingMailer::SETTING, true, 'boolean', 'widgets');
        $booking = $this->booking('web');

        $this->assertTrue(app(CustomerBookingMailer::class)->confirmIfWebBooking($booking));
        Mail::assertSent(BookingConfirmationMail::class, fn ($m) => $m->hasTo('guest@example.com'));

        // Once only — a second call doesn't re-send.
        Mail::fake();
        $this->assertFalse(app(CustomerBookingMailer::class)->confirmIfWebBooking($booking->fresh()));
        Mail::assertNothingSent();
    }

    public function test_nothing_is_sent_when_the_setting_is_off(): void
    {
        // Setting defaults OFF.
        $booking = $this->booking('web');

        $this->assertFalse(app(CustomerBookingMailer::class)->confirmIfWebBooking($booking));
        Mail::assertNothingSent();
    }

    public function test_an_eto_or_imported_customer_is_never_emailed_even_with_the_setting_on(): void
    {
        Setting::set(CustomerBookingMailer::SETTING, true, 'boolean', 'widgets');

        foreach (['import', 'calendar', 'outlook', 'phone', 'ai'] as $source) {
            $booking = $this->booking($source);
            $this->assertFalse(
                app(CustomerBookingMailer::class)->confirmIfWebBooking($booking),
                "source {$source} must never be emailed"
            );
        }

        Mail::assertNothingSent();
    }

    public function test_no_email_without_a_customer_email_address(): void
    {
        Setting::set(CustomerBookingMailer::SETTING, true, 'boolean', 'widgets');
        $booking = $this->booking('web', email: null);

        $this->assertFalse(app(CustomerBookingMailer::class)->confirmIfWebBooking($booking));
        Mail::assertNothingSent();
    }

    public function test_the_web_widgets_admin_page_shows_snippets_and_the_toggle(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('web-widgets.index'))->assertOk()
            ->assertSee('Web Widgets')
            ->assertSee('Mini price check')
            ->assertSee('Automatic customer emails');

        // Toggle it on.
        $this->actingAs($admin)->put(route('web-widgets.update'), ['customer_emails' => '1'])->assertRedirect();
        $this->assertTrue(CustomerBookingMailer::enabled());

        // And off.
        $this->actingAs($admin)->put(route('web-widgets.update'), [])->assertRedirect();
        $this->assertFalse(CustomerBookingMailer::enabled());
    }

    public function test_the_widgets_page_is_admin_only(): void
    {
        $driver = User::factory()->driver()->create();
        $this->actingAs($driver)->get(route('web-widgets.index'))->assertForbidden();
    }
}
