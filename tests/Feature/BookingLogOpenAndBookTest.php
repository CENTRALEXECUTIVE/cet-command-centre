<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingAttempt;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 27 Nov 2026: a customer pressed Book, the log showed a row with no booking, and the
 * office couldn't open it or act on it. Every entry opens in full and can be turned into
 * a booking; a reused page code from an older trip never swallows a new booking.
 */
class BookingLogOpenAndBookTest extends TestCase
{
    use RefreshDatabase;

    private function base(array $o = []): array
    {
        return array_merge([
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH',
            'destination_address' => 'Rotherham S60 5WG', 'destination_postcode' => 'S60 5WG',
            'pickup_at' => now()->addDays(10)->setTime(11, 10)->format('Y-m-d\TH:i'),
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->value('id'), 'passengers' => 2,
            'customer_first_name' => 'Open', 'customer_surname' => 'Tester',
            'customer_phone' => '07700900778', 'customer_email' => 'open@example.com',
            'flight_number' => 'ba1234', 'notes' => 'Two big cases',
        ], $o);
    }

    public function test_an_old_page_code_for_a_different_trip_still_makes_a_new_booking(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        $token = 'same-old-page-token';
        $this->post(route('widget.book.store'), $this->base(['submission_token' => $token]))->assertOk();
        $this->assertSame(1, Booking::where('is_return_leg', false)->count());

        // Same page code, a different pickup time and place = a new trip, a new booking.
        $this->travel(5)->minutes();
        $this->post(route('widget.book.store'), $this->base([
            'submission_token' => $token,
            'pickup_address' => 'Barnsley S70 2TA', 'pickup_postcode' => 'S70 2TA',
            'pickup_at' => now()->addDays(12)->setTime(9, 0)->format('Y-m-d\TH:i'),
        ]))->assertOk();
        $this->assertSame(2, Booking::where('is_return_leg', false)->count());
        $this->assertNotSame('submitted', BookingAttempt::latest('id')->first()->outcome);
    }

    public function test_a_true_double_click_saves_both_and_each_log_entry_opens_its_booking(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        $data = $this->base(['submission_token' => 'tok-1']);
        $this->post(route('widget.book.store'), $data)->assertOk();
        $this->post(route('widget.book.store'), $data)->assertOk();
        $this->assertSame(2, Booking::where('is_return_leg', false)->count());
        $this->assertSame(0, BookingAttempt::where('outcome', 'submitted')->count());
        $this->assertSame(2, BookingAttempt::whereNotNull('booking_id')->count());
    }

    public function test_an_entry_opens_in_full_and_prefills_new_booking(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        $a = BookingAttempt::create([
            'form' => 'widget', 'outcome' => 'submitted', 'customer_name' => 'Claire Example',
            'contact' => '+447700900111 · claire@example.com',
            'pickup' => 'Manchester Airport T2', 'destination' => 'Unit 3A Bessemer Park, Sheffield',
            'pickup_at' => '2026-12-07T11:10',
            'details' => ['vehicle' => 'Executive', 'form' => [
                'pickup_address' => 'Manchester Airport T2', 'destination_address' => 'Unit 3A Bessemer Park, Sheffield',
                'pickup_at' => '2026-12-07T11:10', 'journey_type' => 'return', 'return_pickup_at' => '2026-12-08T15:00',
                'lead_passenger_name' => 'Bill Example', 'flight_number' => 'LH940', 'passengers' => '1',
            ]],
            'created_at' => now(),
        ]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('booking-log.index'))->assertOk()
            ->assertSee(route('booking-log.show', $a), false)->assertSee('Make booking from this');
        $this->actingAs($admin)->get(route('booking-log.show', $a))->assertOk()
            ->assertSee('Bill Example')->assertSee('LH940')->assertSee('2026-12-08T15:00');

        $this->actingAs($admin)->get(route('booking-log.book', $a))->assertRedirect(route('bookings.create'));
        $page = $this->actingAs($admin)->get(route('bookings.create'))->assertOk();
        $page->assertSee('Claire Example')->assertSee('claire@example.com')->assertSee('Bill Example')
            ->assertSee('Unit 3A Bessemer Park, Sheffield')->assertSee('2026-12-07T11:10')->assertSee('2026-12-08T15:00');
        $this->assertSame(0, Booking::count(), 'nothing is saved until the office presses save');

        $this->actingAs(User::factory()->create())->get(route('booking-log.show', $a))->assertForbidden();
    }

    public function test_full_form_details_are_kept_without_passwords(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        $this->post(route('widget.book.store'), $this->base(['password' => 'secret123', 'pickup_at' => now()->subDay()->format('Y-m-d\TH:i')]));
        $a = BookingAttempt::latest('id')->first();
        $this->assertSame('refused', $a->outcome);
        $this->assertSame('BA1234', strtoupper($a->details['form']['flight_number']));
        $this->assertArrayNotHasKey('password', $a->details['form']);
    }

    public function test_a_press_that_saves_nothing_alerts_and_emails_the_office_every_detail(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        config(['cet.ops_email' => 'office@example.com']);
        $sent = [];
        \Illuminate\Support\Facades\Mail::shouldReceive('raw')->once()->andReturnUsing(function ($body) use (&$sent) {
            $sent[] = $body;
        });
        $req = \Illuminate\Http\Request::create('/x', 'POST', $this->base(['password' => 'secret123', 'vat_invoice' => '1',
            'lead_passenger_name' => 'Bill Example', 'return_pickup_at' => '2026-12-08T15:00']));
        \App\Support\UnsavedBookingBackup::send($req, 'test');
        app()->terminate();
        $this->assertStringContainsString('Bill Example', $sent[0]);
        $this->assertStringContainsString('2026-12-08T15:00', $sent[0]);
        $this->assertStringContainsString('Executive', $sent[0]);
        $this->assertStringNotContainsString('secret123', $sent[0]);
        $this->assertSame(1, \App\Models\WatchdogEvent::where('severity', 'critical')->where('title', 'like', '%NOT saved%')->count());
    }

    public function test_a_spam_bot_never_triggers_the_backup_alert(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        \Illuminate\Support\Facades\Mail::fake();
        $this->post(route('widget.book.store'), $this->base(['company' => 'bot-filled']))->assertOk();
        $this->assertSame(0, \App\Models\WatchdogEvent::where('title', 'like', '%NOT saved%')->count());
        $this->assertSame('spam', BookingAttempt::latest('id')->first()->outcome);
        $this->assertSame(0, Booking::count());
    }

    public function test_a_crash_on_the_booking_form_alerts_and_emails_the_office(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        config(['cet.ops_email' => 'office@example.com']);
        \Illuminate\Support\Facades\Mail::fake();
        \App\Models\Customer::creating(fn () => throw new \RuntimeException('database down'));
        $this->withoutExceptionHandling();
        try {
            $this->post(route('widget.book.store'), $this->base());
            $this->fail('expected the crash to surface');
        } catch (\RuntimeException) {
        }
        $this->assertSame('error', BookingAttempt::latest('id')->first()->outcome);
        $this->assertSame(1, \App\Models\WatchdogEvent::where('severity', 'critical')->where('title', 'like', '%NOT saved%')->count());
    }

    public function test_rolls_royce_only_on_request_never_shows_in_any_view(): void
    {
        BookingAttempt::create(['form' => 'prices', 'outcome' => 'price_on_request', 'pickup' => 'RR ONLY ROUTE', 'destination' => 'X',
            'details' => ['vehicles' => ['Rolls Royce Ghost'], 'journey_type' => 'one_way'], 'created_at' => now()]);
        BookingAttempt::create(['form' => 'prices', 'outcome' => 'price_on_request', 'pickup' => 'EXEC ROUTE', 'destination' => 'X',
            'details' => ['vehicles' => ['Executive'], 'journey_type' => 'one_way'], 'created_at' => now()]);
        $admin = User::factory()->admin()->create();
        foreach (['problems', 'all'] as $show) {
            $this->actingAs($admin)->get(route('booking-log.index', ['show' => $show]))->assertOk()
                ->assertDontSee('RR ONLY ROUTE')->assertSee('EXEC ROUTE');
        }
    }
}
