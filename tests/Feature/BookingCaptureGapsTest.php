<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingAttempt;
use App\Models\WatchdogEvent;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * 27 Nov 2026 gap review: nothing a customer does on the booking form goes unrecorded —
 * a press stopped in the browser, a form left without booking, an expired page.
 */
class BookingCaptureGapsTest extends TestCase
{
    use RefreshDatabase;

    private function typed(array $o = []): array
    {
        return array_merge([
            'pickup_address' => 'Manchester Airport T2', 'destination_address' => 'Unit 3A Bessemer Park, Sheffield',
            'pickup_at' => '2026-12-07T11:10', 'customer_first_name' => 'Claire', 'customer_surname' => 'Example',
            'customer_email' => 'someone@jeld-wen.com', 'customer_phone' => '07700900111', 'password' => 'secret123',
        ], $o);
    }

    public function test_a_press_stopped_in_the_browser_is_logged_with_what_was_typed(): void
    {
        $this->post(route('widget.beacon'), $this->typed(['beacon_kind' => 'blocked', 'beacon_message' => 'Please enter your flight’s landing time so we can track it.']))
            ->assertNoContent();
        $a = BookingAttempt::latest('id')->first();
        $this->assertSame('blocked', $a->outcome);
        $this->assertStringContainsString('landing time', $a->details['message']);
        $this->assertSame('Unit 3A Bessemer Park, Sheffield', $a->details['form']['destination_address']);
        $this->assertArrayNotHasKey('password', $a->details['form']);
        $this->assertArrayNotHasKey('beacon_kind', $a->details['form']);
    }

    public function test_a_form_left_without_booking_alerts_the_office_after_15_minutes(): void
    {
        $this->post(route('widget.beacon'), $this->typed(['beacon_kind' => 'abandoned']))->assertNoContent();
        $this->assertSame('abandoned', BookingAttempt::latest('id')->first()->outcome);

        $this->artisan('cet:abandoned-bookings');
        $this->assertSame(0, WatchdogEvent::count(), 'not before 15 minutes');

        $this->travel(16)->minutes();
        $this->artisan('cet:abandoned-bookings');
        $ev = WatchdogEvent::latest('id')->first();
        $this->assertNotNull($ev);
        $this->assertStringContainsString("didn't finish", $ev->title);

        $this->artisan('cet:abandoned-bookings');
        $this->assertSame(1, WatchdogEvent::count(), 'alerts once');
    }

    public function test_no_alert_if_they_booked_after_all(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        $this->post(route('widget.beacon'), $this->typed(['beacon_kind' => 'abandoned']))->assertNoContent();
        $c = \App\Models\Customer::create(['name' => 'Claire Example', 'email' => 'someone@jeld-wen.com', 'phone' => '07700900111']);
        Booking::factory()->create(['customer_id' => $c->id]);
        $this->travel(16)->minutes();
        $this->artisan('cet:abandoned-bookings');
        $this->assertSame(0, WatchdogEvent::where('title', 'like', '%didn%')->count());
    }

    public function test_an_expired_page_press_is_captured_alerted_and_the_customer_sent_back(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        config(['cet.ops_email' => 'office@example.com']);
        Mail::fake();
        // Force the real CSRF check (tests normally skip it).
        $this->app->instance(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, new class($this->app, $this->app["encrypter"]) extends \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });

        $this->from(route('widget.book'))->post(route('widget.book.store'), $this->typed(['_token' => 'stale']))
            ->assertRedirect(route('widget.book'))->assertSessionHasErrors('pickup_at');

        $a = BookingAttempt::latest('id')->first();
        $this->assertSame('error', $a->outcome);
        $this->assertStringContainsString('Page expired', $a->details['message']);
        $this->assertTrue(WatchdogEvent::where('severity', 'critical')->where('title', 'like', '%NOT saved%')->exists());
    }

    public function test_the_phone_check_in_the_browser_never_blocks(): void
    {
        $html = $this->get(route('widget.book'))->assertOk()->getContent();
        $this->assertStringNotContainsString("showErr(3, phoneMsg)", $html);
        $this->assertStringContainsString('widget\\/book\\/beacon', $html);
    }

    public function test_every_field_on_the_booking_form_is_kept_in_the_log(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        $vClass = \App\Models\VehicleType::where('slug', 'v-class')->first();
        $all = [
            'journey_type' => 'return', 'pickup_address' => 'Manchester Airport T2', 'pickup_postcode' => 'M90 1QX',
            'destination_address' => 'Unit 3A Bessemer Park, Sheffield', 'destination_postcode' => 'S9 1DZ',
            'pickup_at' => '2026-12-07T11:10', 'return_pickup_at' => '2026-12-08T15:00',
            'stops' => ['Meadowhall, Sheffield', 'Tinsley, Sheffield'],
            'vehicle_type_id' => (string) $vClass->id, 'passengers' => '3', 'suitcases' => '2', 'hand_luggage' => '1',
            'flight_number' => 'LH940', 'flight_landing_at' => '2026-12-07T10:40', 'other_flight_number' => 'LH941',
            'meet_greet' => '1', 'need_child_seat' => '1', 'child_seats' => '1', 'booster_seats' => '1',
            'customer_first_name' => 'Claire', 'customer_surname' => 'Example', 'customer_phone' => '07700900111', 'customer_email' => 'someone@jeld-wen.com',
            'booking_for_other' => '1', 'lead_passenger_first_name' => 'Bill', 'lead_passenger_surname' => 'Example', 'lead_passenger_phone' => '07700900222',
            'vat_invoice' => '1', 'business_name' => 'JELD-WEN UK Ltd', 'business_address' => 'Bessemer Park, Sheffield', 'business_vat_number' => 'GB123',
            'payment_method' => 'card', 'notes' => 'Large case', 'voucher' => 'SAVE10', 'heard_about' => 'google',
        ];
        $this->post(route('widget.beacon'), $all + ['beacon_kind' => 'abandoned', 'password' => 'secret123'])->assertNoContent();
        $a = BookingAttempt::latest('id')->first();
        $kept = $a->details['form'];
        foreach ($all as $k => $v) {
            $this->assertArrayHasKey($k, $kept, "field {$k} must be kept");
        }
        $this->assertSame('Meadowhall, Sheffield | Tinsley, Sheffield', $kept['stops']);
        $this->assertSame($vClass->name, $a->details['vehicle'], 'the chosen vehicle is named');
        $this->assertArrayNotHasKey('password', $kept);

        $admin = \App\Models\User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('booking-log.show', $a))->assertOk()
            ->assertSee($vClass->name)->assertSee('2026-12-08T15:00')->assertSee('Bill')->assertSee('Meadowhall, Sheffield')->assertSee('LH941');
    }
}
