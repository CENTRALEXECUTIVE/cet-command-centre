<?php

namespace Tests\Feature;

use App\Mail\BookingConfirmationMail;
use App\Mail\PaymentLinkMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\User;
use App\Services\Payments\PaymentGateway;
use App\Support\BookingEmailLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The booking communications pack on the booking page: resend confirmation (in the
 * right state), "Sending as" labels, per-booking email history, the "booking
 * updated" email, and the one-tap resend payment link that reuses an open link.
 * Every send is a manual office button press.
 */
class BookingCommsPackTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['name' => 'Abdi Office']);
    }

    private function booking(array $attrs = [], array $meta = [], ?string $email = 'p@example.com'): Booking
    {
        $customer = Customer::create(['name' => 'Pauric Shanley', 'email' => $email, 'phone' => '07700900123']);
        $b = Booking::factory()->create(array_merge([
            'customer_id' => $customer->id, 'source' => 'phone',
            'payment_method' => 'card', 'payment_status' => 'pending', 'quoted_price' => 100,
            'pickup_at' => now()->addDays(2),
        ], $attrs));
        if ($meta) {
            $b->forceFill(['meta' => array_merge($b->meta ?? [], $meta)])->save();
        }

        return $b->fresh();
    }

    /* ── 1. Resend booking confirmation ─────────────────────────────────── */

    public function test_resend_on_a_paid_booking_sends_the_paid_confirmation(): void
    {
        Mail::fake();
        $b = $this->booking(['payment_status' => 'paid']);

        $this->actingAs($this->admin())->post(route('bookings.resend-confirmation', $b))
            ->assertRedirect()->assertSessionHas('status');

        Mail::assertSent(BookingConfirmationMail::class, fn ($m) => $m->booking->is($b)
            && $m->paid === true && $m->payUrl === null && $m->updated === false);
    }

    public function test_resend_with_a_balance_due_includes_the_pay_link(): void
    {
        Mail::fake();
        $b = $this->booking();

        $this->actingAs($this->admin())->post(route('bookings.resend-confirmation', $b))->assertSessionHas('status');

        Mail::assertSent(BookingConfirmationMail::class, fn ($m) => $m->paid === false
            && str_contains((string) $m->payUrl, '/widget/pay/'.$b->id));
    }

    public function test_resend_on_cash_and_account_jobs_has_no_pay_button(): void
    {
        Mail::fake();
        $cash = $this->booking(['payment_method' => 'cash']);
        $account = $this->booking(['payment_method' => 'account']);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('bookings.resend-confirmation', $cash))->assertSessionHas('status');
        $this->actingAs($admin)->post(route('bookings.resend-confirmation', $account))->assertSessionHas('status');

        Mail::assertSent(BookingConfirmationMail::class, 2);
        Mail::assertSent(BookingConfirmationMail::class, fn ($m) => $m->booking->is($cash) && ! $m->paid && $m->payUrl === null);
        Mail::assertSent(BookingConfirmationMail::class, fn ($m) => $m->booking->is($account) && ! $m->paid && $m->payUrl === null);
    }

    public function test_a_paid_booking_re_priced_up_resends_as_paid_with_no_dead_end_pay_button(): void
    {
        Mail::fake();
        // £55 taken online, then the office raised the price to £100.
        $b = $this->booking(['payment_status' => 'paid'], ['square_payment' => ['id' => 'sq_1', 'amount' => 55]]);
        $this->assertEqualsWithDelta(45.0, $b->transactionsAmountDue(), 0.001);

        $this->actingAs($this->admin())->post(route('bookings.resend-confirmation', $b))->assertSessionHas('status');

        Mail::assertSent(BookingConfirmationMail::class, fn ($m) => $m->paid === true && $m->payUrl === null);
    }

    public function test_resend_is_refused_for_a_cancelled_booking(): void
    {
        Mail::fake();
        $b = $this->booking(['status' => 'cancelled']);

        $this->actingAs($this->admin())->post(route('bookings.resend-confirmation', $b))->assertSessionHas('error');

        Mail::assertNothingSent();
    }

    public function test_resend_with_no_customer_email_errors_cleanly(): void
    {
        Mail::fake();
        $b = $this->booking(email: null);

        $this->actingAs($this->admin())->post(route('bookings.resend-confirmation', $b))->assertSessionHas('error');

        Mail::assertNothingSent();
    }

    public function test_an_imported_eto_booking_is_never_emailed_without_send_anyway(): void
    {
        Mail::fake();
        $b = $this->booking(['source_system' => 'eto']);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('bookings.resend-confirmation', $b))->assertSessionHas('error');
        $this->actingAs($admin)->post(route('bookings.updated-email', $b))->assertSessionHas('error');
        Mail::assertNothingSent();

        $this->actingAs($admin)->post(route('bookings.resend-confirmation', $b), ['send_anyway' => 1])->assertSessionHas('status');
        Mail::assertSent(BookingConfirmationMail::class, 1);
    }

    public function test_comms_actions_are_admin_only(): void
    {
        $b = $this->booking();
        $driver = User::factory()->driver()->create();

        $this->actingAs($driver)->post(route('bookings.resend-confirmation', $b))->assertForbidden();
        $this->actingAs($driver)->post(route('bookings.updated-email', $b))->assertForbidden();
        $this->actingAs($driver)->post(route('bookings.resend-payment-link', $b), ['channel' => 'copy'])->assertForbidden();
    }

    /* ── 2. "Sending as" label ──────────────────────────────────────────── */

    public function test_the_booking_page_shows_which_company_and_address_emails_go_from(): void
    {
        config(['cet.company.email' => 'admin@centralexecutivetransfers.co.uk']);
        Setting::set('mail_pvt_enabled', '1', 'string', 'mail');
        Setting::set('mail_pvt_from_address', 'admin@centralexecutivetransferspvt.co.uk', 'string', 'mail');
        $admin = $this->admin();

        $pvt = $this->booking([], ['billing_entity' => 'chauffeurs']);
        $this->actingAs($admin)->get(route('bookings.show', $pvt))->assertOk()
            ->assertSee('Sending as', false)
            ->assertSee('Central Executive Transfers PVT LTD', false)
            ->assertSee('admin@centralexecutivetransferspvt.co.uk', false);

        $vat = $this->booking([], ['billing_entity' => 'transfers', 'vat_invoice_requested' => true]);
        $res = $this->actingAs($admin)->get(route('bookings.show', $vat))->assertOk();
        $res->assertSee('Sending as <strong>Central Executive Transfers Ltd</strong> · admin@centralexecutivetransfers.co.uk', false);
    }

    public function test_the_label_warns_when_a_pvt_booking_would_fall_back_to_the_main_address(): void
    {
        Setting::set('mail_pvt_enabled', '0', 'string', 'mail');
        $b = $this->booking([], ['billing_entity' => 'chauffeurs']);

        $this->actingAs($this->admin())->get(route('bookings.show', $b))->assertOk()
            ->assertSee('PVT sending is off', false);
    }

    /* ── 3. Email history ───────────────────────────────────────────────── */

    public function test_a_sent_confirmation_is_logged_on_the_booking_and_shown(): void
    {
        // Real (array) mailer — the MessageSent listener records the send.
        config(['cet.company.email' => 'admin@centralexecutivetransfers.co.uk']);
        $b = $this->booking([], ['billing_entity' => 'transfers']);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('bookings.resend-confirmation', $b))->assertSessionHas('status');

        $log = BookingEmailLog::entries($b->fresh());
        $this->assertCount(1, $log);
        $this->assertSame('Confirmation + pay link', $log[0]['type']);
        $this->assertSame('p@example.com', $log[0]['to']);
        $this->assertSame('admin@centralexecutivetransfers.co.uk', $log[0]['from']);
        $this->assertStringContainsString($b->reference, $log[0]['subject']);
        $this->assertSame('Abdi Office', $log[0]['by']);
        $this->assertTrue($log[0]['ok']);

        $this->actingAs($admin)->get(route('bookings.show', $b))->assertOk()
            ->assertSee('Emails sent (1)', false)
            ->assertSee('Confirmation + pay link', false);
    }

    public function test_a_failed_send_is_logged_as_failed(): void
    {
        $b = $this->booking();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP connection refused'));

        $this->actingAs($this->admin())->post(route('bookings.resend-confirmation', $b))->assertSessionHas('error');

        $log = BookingEmailLog::entries($b->fresh());
        $this->assertCount(1, $log);
        $this->assertFalse($log[0]['ok']);
        $this->assertSame('p@example.com', $log[0]['to']);
        $this->assertStringContainsString('SMTP connection refused', $log[0]['error']);
    }

    public function test_the_automatic_paid_confirmation_is_logged_and_its_own_flag_save_keeps_the_history(): void
    {
        $b = $this->booking(['payment_status' => 'paid', 'source' => 'web']);

        // Sends, then saves paid_email_sent from its in-memory model.
        app(\App\Services\BookingStatusService::class)->emailPaidConfirmation($b);

        $fresh = $b->fresh();
        $this->assertNotEmpty($fresh->meta['paid_email_sent'] ?? null);
        $log = BookingEmailLog::entries($fresh);
        $this->assertCount(1, $log);
        $this->assertSame('Paid confirmation', $log[0]['type']);
        $this->assertSame('System (automatic)', $log[0]['by']);
    }

    public function test_a_stale_model_saving_meta_cannot_wipe_the_email_history(): void
    {
        $b = $this->booking();
        $stale = Booking::find($b->id); // loaded BEFORE the email is logged

        BookingEmailLog::record(Booking::find($b->id), ['type' => 'Payment link', 'to' => 'p@example.com', 'ok' => true]);

        $stale->forceFill(['meta' => array_merge($stale->meta ?? [], ['something' => 'else'])])->save();

        $fresh = $b->fresh();
        $this->assertSame('else', $fresh->meta['something']);
        $this->assertCount(1, BookingEmailLog::entries($fresh));
    }

    public function test_the_history_is_capped(): void
    {
        $b = $this->booking();
        for ($i = 0; $i < BookingEmailLog::CAP + 5; $i++) {
            BookingEmailLog::record($b, ['type' => 'Payment link', 'to' => 'n'.$i.'@example.com', 'ok' => true]);
        }

        $log = BookingEmailLog::entries($b->fresh());
        $this->assertCount(BookingEmailLog::CAP, $log);
        $this->assertSame('n'.(BookingEmailLog::CAP + 4).'@example.com', $log[0]['to']); // newest first
    }

    /* ── 4. "Booking updated" email ─────────────────────────────────────── */

    public function test_the_updated_email_is_sent_with_an_updated_subject_and_the_current_pay_link(): void
    {
        Mail::fake();
        $b = $this->booking();

        $this->actingAs($this->admin())->post(route('bookings.updated-email', $b))->assertSessionHas('status');

        Mail::assertSent(BookingConfirmationMail::class, function ($m) use ($b) {
            return $m->updated === true && $m->paid === false && $m->payUrl !== null
                && str_starts_with($m->envelope()->subject, 'Updated:')
                && str_contains($m->render(), 'Your booking has been updated')
                && $m->booking->is($b);
        });
    }

    public function test_the_updated_preview_renders_without_sending(): void
    {
        Mail::fake();
        $b = $this->booking();

        $this->actingAs($this->admin())->get(route('bookings.confirmation-email.preview', ['booking' => $b, 'updated' => 1]))
            ->assertOk()->assertSee('Your booking has been updated', false);
        $this->actingAs($this->admin())->get(route('bookings.confirmation-email.preview', $b))
            ->assertOk()->assertDontSee('Your booking has been updated', false);

        Mail::assertNothingSent();
    }

    public function test_re_pricing_never_emails_but_prompts_the_office_to_send_the_update(): void
    {
        Mail::fake();
        $b = $this->booking();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('bookings.price', $b), ['final_price' => 120])->assertRedirect();

        Mail::assertNothingSent();
        $this->assertNotEmpty($b->fresh()->meta['last_edited_at'] ?? null);
        $this->actingAs($admin)->get(route('bookings.show', $b))->assertOk()
            ->assertSee('This booking was changed', false)
            ->assertSee('Email the customer the updated booking', false);
    }

    /* ── 5. Resend payment link ─────────────────────────────────────────── */

    public function test_resend_reuses_the_open_link_for_the_amount_due(): void
    {
        Mail::fake();
        $this->mock(PaymentGateway::class, fn ($m) => $m->shouldNotReceive('createCheckoutUrl'));
        $b = $this->booking();
        $b->payments()->create(['method' => 'card', 'amount' => 100, 'status' => 'link_sent',
            'tide_payment_link' => 'https://pay.example/existing', 'meta' => ['name' => 'Balance']]);

        $this->actingAs($this->admin())->post(route('bookings.resend-payment-link', $b), ['channel' => 'email'])
            ->assertSessionHas('copy_link', 'https://pay.example/existing')
            ->assertSessionHas('link_reused');

        $this->assertSame(1, $b->payments()->count()); // no duplicate line
        Mail::assertSent(PaymentLinkMail::class, fn ($m) => $m->payUrl === 'https://pay.example/existing' && (float) $m->amount === 100.0);
    }

    public function test_resend_creates_one_link_then_reuses_it(): void
    {
        $this->mock(PaymentGateway::class, fn ($m) => $m->shouldReceive('createCheckoutUrl')->once()->andReturn('https://pay.example/new'));
        $b = $this->booking();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('bookings.resend-payment-link', $b), ['channel' => 'copy'])
            ->assertSessionHas('copy_link', 'https://pay.example/new');
        $this->actingAs($admin)->post(route('bookings.resend-payment-link', $b), ['channel' => 'whatsapp'])
            ->assertSessionHas('copy_link', 'https://pay.example/new')
            ->assertSessionHas('share_wa');

        $this->assertSame(1, $b->payments()->count());
        $this->assertDatabaseHas('payments', ['booking_id' => $b->id, 'amount' => 100, 'status' => 'link_sent', 'tide_payment_link' => 'https://pay.example/new']);
    }

    public function test_a_link_for_a_different_amount_is_not_reused(): void
    {
        $this->mock(PaymentGateway::class, fn ($m) => $m->shouldReceive('createCheckoutUrl')->once()
            ->withArgs(fn ($booking, $amount) => abs($amount - 100) < 0.001)->andReturn('https://pay.example/current'));
        $b = $this->booking();
        // An old £80 link from before the price went to £100.
        $b->payments()->create(['method' => 'card', 'amount' => 80, 'status' => 'link_sent', 'tide_payment_link' => 'https://pay.example/stale']);

        $this->actingAs($this->admin())->post(route('bookings.resend-payment-link', $b), ['channel' => 'copy'])
            ->assertSessionHas('copy_link', 'https://pay.example/current');
    }

    public function test_resend_link_refuses_a_paid_booking_re_priced_up(): void
    {
        $this->mock(PaymentGateway::class, fn ($m) => $m->shouldNotReceive('createCheckoutUrl'));
        $b = $this->booking(['payment_status' => 'paid'], ['square_payment' => ['id' => 'sq_1', 'amount' => 55]]);

        $this->actingAs($this->admin())->post(route('bookings.resend-payment-link', $b), ['channel' => 'copy'])
            ->assertSessionHas('error');

        $this->assertSame(0, $b->payments()->count());
    }

    public function test_resend_link_without_a_provider_leaves_no_orphan_line(): void
    {
        $this->mock(PaymentGateway::class, fn ($m) => $m->shouldReceive('createCheckoutUrl')->andReturn(null));
        $b = $this->booking();

        $this->actingAs($this->admin())->post(route('bookings.resend-payment-link', $b), ['channel' => 'copy'])
            ->assertSessionHas('error');

        $this->assertSame(0, $b->payments()->count());
    }

    public function test_the_booking_page_shows_the_resend_payment_link_action_when_a_balance_is_due(): void
    {
        $b = $this->booking();
        $b->payments()->create(['method' => 'card', 'amount' => 100, 'status' => 'link_sent', 'tide_payment_link' => 'https://pay.example/existing']);

        $this->actingAs($this->admin())->get(route('bookings.show', $b))->assertOk()
            ->assertSee('Resend payment link', false)
            ->assertSee('Resends your existing £100.00 card link', false);
    }
}
