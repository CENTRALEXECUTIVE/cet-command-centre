<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Mail\BookingConfirmationMail;
use App\Models\Booking;
use App\Models\CorporateAccount;
use App\Models\CorporateContact;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\BookingStatusService;
use App\Services\Payments\InvoiceService;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Booking health check (Nov 2026) — gaps the suite didn't cover, each found as a
 * real money/email bug:
 *
 *  1. Office "Mark paid" → the 5-minute reconcile sweep emailed the customer the
 *     paid confirmation anyway (the office-only rule was bypassed).
 *  2. A £220 return paid in one go sent the customer TWO receipts, the first
 *     reading "Paid £115, Remaining £105" on a trip already paid in full.
 *  3. A signed-in account customer booking a RETURN on the widget had the return
 *     leg saved as a card job off the account — so the monthly account invoice
 *     missed it and the office saw it "awaiting card payment".
 */
class BookingHealthCheckTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Booking, 1: Booking} [outbound £115, return £105] */
    private function webReturnPair(): array
    {
        $customer = Customer::create(['name' => 'Pauric Shanley', 'email' => 'p@example.com', 'phone' => '00353860889895']);
        [$outRef, $retRef] = Booking::generateReferencePair();
        $common = ['customer_id' => $customer->id, 'source' => 'web', 'payment_method' => 'card',
            'payment_status' => 'pending', 'journey_type' => 'return', 'status' => BookingStatus::Pending];

        $out = Booking::factory()->create($common + ['reference' => $outRef, 'quoted_price' => 115,
            'pickup_at' => now()->addDays(3), 'pickup_address' => 'Manchester Airport', 'destination_address' => 'Sheffield S1 4LR']);
        $ret = Booking::factory()->create($common + ['reference' => $retRef, 'is_return_leg' => true, 'quoted_price' => 105,
            'pickup_at' => now()->addDays(5), 'pickup_address' => 'Sheffield S1 4LR', 'destination_address' => 'Manchester Airport']);
        foreach ([[$out, $ret], [$ret, $out]] as [$a, $b]) {
            $a->forceFill(['meta' => array_merge($a->meta ?? [], ['billing_entity' => 'chauffeurs']), 'linked_booking_id' => $b->id])->save();
        }

        return [$out->fresh(), $ret->fresh()];
    }

    public function test_the_reconcile_sweep_never_emails_the_customer_after_an_office_mark_paid(): void
    {
        config(['cet.ops_email' => 'ops@example.com']);
        Mail::fake();
        $customer = Customer::create(['name' => 'Jo Payer', 'email' => 'jo@example.com', 'phone' => '07700900500']);
        $booking = Booking::factory()->create([
            'customer_id' => $customer->id, 'source' => 'phone',
            'payment_method' => 'card', 'payment_status' => 'pending',
            'quoted_price' => 120, 'pickup_at' => now()->addDays(2), 'status' => BookingStatus::Pending,
        ]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('payments.paid', $booking))->assertRedirect();
        Mail::assertNotSent(BookingConfirmationMail::class);

        // The every-5-minutes safety net must not "retry" a customer email the office
        // deliberately didn't send.
        $this->artisan('cet:reconcile-payments')->assertSuccessful();
        Mail::assertNotSent(BookingConfirmationMail::class);
        $this->assertSame('paid', $booking->fresh()->payment_status);
    }

    public function test_a_return_paid_in_one_go_gets_one_receipt_that_reads_paid_in_full(): void
    {
        config(['cet.ops_email' => 'ops@example.com']);
        [$out, $ret] = $this->webReturnPair();

        // The customer pays the whole £220 trip on the outbound's link.
        $out->markFarePaid('pi_trip', 220, 'chauffeurs');
        app(BookingStatusService::class)->onFarePaid($out->fresh());

        // Real rendered mail (array transport), as the customer reads it.
        $receipts = collect(app('mailer')->getSymfonyTransport()->messages())
            ->map(fn ($m) => $m->getOriginalMessage())
            ->filter(fn ($m) => collect($m->getTo())->contains(fn ($a) => $a->getAddress() === 'p@example.com'))
            ->values();

        $this->assertCount(1, $receipts, 'one payment for one trip = one receipt');
        $text = preg_replace('/\s+/', ' ', strip_tags((string) $receipts[0]->getHtmlBody()));
        $this->assertStringContainsString('Paid in full', $text);
        $this->assertStringNotContainsString('Remaining', $text);
        $this->assertTrue($ret->fresh()->fareIsPaid());

        // Neither the sweep nor opening the return leg sends a second copy.
        Mail::fake();
        $this->artisan('cet:reconcile-payments')->assertSuccessful();
        $this->actingAs(User::factory()->admin()->create())->get(route('bookings.show', $ret))->assertOk();
        Mail::assertNotSent(BookingConfirmationMail::class);
    }

    public function test_an_office_ledger_payment_for_the_whole_trip_never_auto_emails_the_customer(): void
    {
        config(['cet.ops_email' => 'ops@example.com']);
        Mail::fake();
        [$out, $ret] = $this->webReturnPair();

        // The office records £220 taken by phone on the outbound's ledger.
        $out->payments()->create(['method' => 'card', 'amount' => 220, 'status' => 'paid', 'paid_at' => now()]);
        app(BookingStatusService::class)->onFarePaid($out->fresh(), emailCustomer: false);
        $this->assertTrue($ret->fresh()->fareIsPaid(), 'the whole-trip payment settles the return');

        // Opening the return leg, or the sweep, must not email the customer.
        $this->actingAs(User::factory()->admin()->create())->get(route('bookings.show', $ret))->assertOk();
        $this->artisan('cet:reconcile-payments')->assertSuccessful();
        Mail::assertNotSent(BookingConfirmationMail::class);
    }

    /**
     * The suite pins UTC, which can't catch a BST hour being added. Run a summer
     * booking through the admin form AND the web widget in the production timezone:
     * 09:00 typed = 09:00 stored, on the email and on the calendar (+01:00).
     */
    public function test_a_summer_pickup_stays_at_the_typed_uk_time_in_the_production_timezone(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        $previous = date_default_timezone_get();
        config(['app.timezone' => 'Europe/London']);
        date_default_timezone_set('Europe/London');

        try {
            $day = now()->addYear()->setDate((int) now()->addYear()->format('Y'), 7, 15)->format('Y-m-d');
            $executive = VehicleType::where('slug', 'executive')->first();

            $this->actingAs(User::factory()->admin()->create())->post(route('bookings.store'), [
                'customer_name' => 'Summer Office', 'customer_phone' => '07700900601', 'customer_email' => 'so@example.com',
                'vehicle_type_id' => $executive->id, 'journey_type' => 'one_way',
                'pickup_at' => $day.'T09:00', 'pickup_address' => '1 Test St, Sheffield S1 2HH',
                'destination_address' => 'Manchester Airport', 'passengers' => 2,
                'payment_method' => 'card', 'quoted_price' => 105, 'privacy_consent' => '1',
            ])->assertRedirect();

            $this->post(route('widget.book.store'), [
                'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH',
                'destination_address' => 'Manchester Airport',
                'pickup_at' => $day.'T09:00',
                'vehicle_type_id' => $executive->id, 'passengers' => 2,
                'customer_name' => 'Summer Web', 'customer_phone' => '07700900602',
            ])->assertOk();

            $this->assertSame(2, Booking::count());
            foreach (Booking::all() as $booking) {
                $this->assertSame($day.' 09:00', $booking->pickup_at->format('Y-m-d H:i'), $booking->source.' pickup moved');
                $event = app(\App\Services\CalendarEventBuilder::class)->buildFor($booking->fresh(['customer', 'vehicleType']));
                $this->assertSame($day.'T09:00:00+01:00', $event->start_at->toRfc3339String());
                $html = (new BookingConfirmationMail($booking->fresh()))->render();
                $this->assertStringContainsString('09:00', $html);
                $this->assertStringContainsString('UTC+1 London (BST)', $html);
            }
        } finally {
            config(['app.timezone' => 'UTC']);
            date_default_timezone_set($previous);
        }
    }

    public function test_an_account_customer_booking_a_return_on_the_widget_bills_both_legs_to_the_account(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        $account = CorporateAccount::create([
            'name' => 'MEPS International Ltd', 'account_code' => '1001',
            'slug' => 'meps-international', 'is_active' => true, 'payment_terms_days' => 30,
        ]);
        CorporateContact::create([
            'corporate_account_id' => $account->id, 'name' => 'Jayne Craven',
            'email' => 'jcraven@meps.co.uk', 'is_primary' => true,
        ]);
        $customer = Customer::create([
            'name' => 'Jayne Craven', 'email' => 'jcraven@meps.co.uk',
            'phone' => '07700900123', 'corporate_account_id' => $account->id,
        ]);
        $executive = VehicleType::where('slug', 'executive')->first();

        $this->withSession(['widget_customer_id' => $customer->id])
            ->post(route('widget.book.store'), [
                'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH',
                'destination_address' => 'Manchester Airport',
                'journey_type' => 'return',
                'pickup_at' => now()->addDays(3)->format('Y-m-d\TH:i'),
                'return_pickup_at' => now()->addDays(5)->format('Y-m-d\TH:i'),
                'flight_number' => 'BA1368',
                'flight_landing_at' => now()->addDays(5)->format('Y-m-d\TH:i'),
                'vehicle_type_id' => $executive->id, 'passengers' => 2,
                'customer_name' => 'Jayne Craven', 'customer_email' => 'jcraven@meps.co.uk',
                'payment_method' => 'account',
            ])->assertOk();

        $out = Booking::where('is_return_leg', false)->firstOrFail();
        $ret = Booking::where('is_return_leg', true)->firstOrFail();

        foreach ([$out, $ret] as $leg) {
            $this->assertSame(\App\Enums\PaymentMethod::Account, $leg->payment_method, $leg->reference.' must be on account');
            $this->assertSame($account->id, (int) $leg->corporate_account_id);
        }

        // Both legs (£105 + £115 net) land on the month's account invoice: £220 + VAT = £264.
        $out->forceFill(['status' => BookingStatus::Complete->value])->save();
        $ret->forceFill(['status' => BookingStatus::Complete->value])->save();
        $invoice = app(InvoiceService::class)->generateForAccount($account, now()->startOfDay(), now()->addDays(10));
        $this->assertNotNull($invoice);
        $this->assertEqualsWithDelta(
            (float) $out->quoted_price + (float) $ret->quoted_price,
            (float) $invoice->subtotal, 0.01,
        );
        $this->assertEqualsWithDelta(264.0, (float) $invoice->total, 0.01);
    }
}
