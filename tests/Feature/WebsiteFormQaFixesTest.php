<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Full-system test findings on the website booking forms (24-25 Nov 2026). */
class WebsiteFormQaFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
        config(['cet.customer_password_login' => true]);
    }

    private function base(array $over = []): array
    {
        return array_merge([
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH',
            'destination_address' => 'Rotherham S60 5WG', 'destination_postcode' => 'S60 5WG',
            'pickup_at' => now()->addDays(10)->format('Y-m-d\TH:i'),
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->value('id'), 'passengers' => 1,
            'customer_first_name' => 'New', 'customer_surname' => 'Person',
            'customer_phone' => '07700900123', 'customer_email' => 'new.person@example.com',
        ], $over);
    }

    public function test_registering_with_someone_elses_phone_never_takes_over_their_account(): void
    {
        $victim = Customer::create(['name' => 'Real Owner', 'phone' => '07700900123', 'email' => 'owner@example.com']);
        $victim->setLoginPassword('OwnerPass123');

        $this->post(route('widget.book.store'), $this->base(['create_account' => 1, 'password' => 'Attacker123']))->assertOk();

        $victim->refresh();
        $this->assertTrue($victim->checkPassword('OwnerPass123'));
        $this->assertFalse($victim->checkPassword('Attacker123'));
        $this->assertNotSame($victim->id, session(\App\Http\Controllers\Widget\CustomerAccountController::SESSION_KEY));
        // A different email on the same phone is a different booker.
        $booking = Booking::latest('id')->first();
        $this->assertSame('new.person@example.com', $booking->customer->email);
    }

    public function test_a_brand_new_customer_can_still_create_an_account_at_checkout(): void
    {
        $this->post(route('widget.book.store'), $this->base(['create_account' => 1, 'password' => 'GoodPass123']))->assertOk();
        $c = Customer::where('email', 'new.person@example.com')->first();
        $this->assertTrue($c->checkPassword('GoodPass123'));
    }

    public function test_the_same_email_reuses_the_customer_and_a_phone_only_customer_is_never_given_a_typed_email(): void
    {
        $known = Customer::create(['name' => 'Known', 'phone' => '07700900999', 'email' => 'known@example.com']);
        $this->post(route('widget.book.store'), $this->base(['customer_email' => 'KNOWN@example.com', 'customer_phone' => '07700900555']))->assertOk();
        $this->assertSame($known->id, Booking::latest('id')->first()->customer_id);
        // Review 25 Nov 2026: the phone they typed is kept on the booking (new mobile),
        // and the existing customer record is left alone.
        $b = Booking::latest('id')->first();
        $this->assertStringContainsString('900555', (string) $b->customerContactNumber());
        $this->assertSame('07700900999', $known->fresh()->phone);
        // The same number in another format is not treated as different.
        $this->assertNull($known->fresh()->typedPhoneIfDifferent('+44 7700 900999'));

        // Never attach a typed email to an existing phone-only customer (account takeover via reset).
        $noEmail = Customer::create(['name' => 'No Email', 'phone' => '07700900777']);
        $this->post(route('widget.book.store'), $this->base(['customer_phone' => '07700900777', 'customer_email' => 'stranger@example.com']))->assertOk();
        $this->assertNotSame($noEmail->id, Booking::latest('id')->first()->customer_id);
        $this->assertNull($noEmail->fresh()->email);
    }

    public function test_a_postcode_typed_twice_is_accepted_as_one_postcode(): void
    {
        $this->post(route('widget.book.store'), $this->base(['destination_postcode' => 'S60 5WGS60 5WG']))->assertOk();
        $this->assertSame(1, Booking::count());
    }

    public function test_a_return_before_the_outbound_gets_a_plain_english_message(): void
    {
        $at = now()->addDays(10)->format('Y-m-d\TH:i');
        $this->from(route('widget.book'))->post(route('widget.book.store'), $this->base(['journey_type' => 'return', 'return_pickup_at' => $at, 'pickup_at' => $at]));
        $html = $this->get(route('widget.book'))->assertSee('Your return pickup must be after your outbound pickup', false)->getContent();
        $this->assertStringNotContainsString('return pickup at field', $html);
        $this->assertStringContainsString('var old = {', $html); // choices are put back
    }

    public function test_the_public_book_page_never_says_paid_when_no_payment_was_taken(): void
    {
        $this->post(route('public.book.store'), [
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH', 'destination_address' => 'Manchester Airport M90 1QX',
            'pickup_at' => now()->addDays(5)->format('Y-m-d\TH:i'),
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->value('id'), 'passengers' => 1,
            'customer_first_name' => 'Jo', 'customer_surname' => 'Smith', 'customer_phone' => '07700900123', 'customer_email' => 'jo@example.com',
        ])->assertRedirect(route('public.book.thanks'));

        $this->get(route('public.book.thanks'))->assertOk()
            ->assertSee('Request received')->assertDontSee('your payment was successful', false);
    }

    public function test_the_form_has_the_hourly_account_vat_notice_and_restore_fixes(): void
    {
        $html = $this->get(route('widget.book'))->getContent();
        $this->assertStringContainsString("if (dropIn) dropIn.required = v !== 'hourly';", $html);
        $this->assertStringContainsString('function priceVatOn()', $html);
        $this->assertStringContainsString("vat_invoice: priceVatOn() ? 1 : 0", $html);
        $this->assertStringContainsString("timeZone: 'Europe/London'", $html);
        $this->assertStringContainsString('var picked = ukMs(whenEl.value);', $html);
        $this->assertStringContainsString('id="b-voucher-note"', $html);
    }
}
