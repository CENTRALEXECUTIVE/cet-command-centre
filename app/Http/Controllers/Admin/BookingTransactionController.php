<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\Payments\SquareBookingPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * ETO-style per-booking payment ledger ("Payment history"). Each booking can hold
 * several transactions (full amount, deposit, balance…), each with a method,
 * status and optional card charge. Built on the existing `payments` table — no
 * new schema. The office has full control: add, edit, duplicate, delete, send a
 * payment link (email / SMS) and mark paid.
 */
class BookingTransactionController extends Controller
{
    public function __construct(private readonly SquareBookingPaymentService $square) {}

    private function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:60'],
            'amount' => ['required', 'numeric', 'min:0', 'max:100000'],
            'charge' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'method' => ['required', Rule::in(['card', 'cash', 'account'])],
            'status' => ['required', Rule::in(['pending', 'link_sent', 'paid', 'balance_remaining', 'failed', 'refunded'])],
        ];
    }

    /** Add a transaction to the ledger. */
    public function store(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate($this->rules());

        $payment = $booking->payments()->create([
            'method' => $data['method'],
            'amount' => $data['amount'],
            'status' => $data['status'],
            'paid_at' => $data['status'] === 'paid' ? now() : null,
            'meta' => array_filter([
                'name' => trim((string) ($data['name'] ?? '')) ?: null,
                'charge' => (float) ($data['charge'] ?? 0) ?: null,
            ], fn ($v) => $v !== null),
        ]);

        $booking->load('payments');
        $booking->reconcilePaymentStatusFromTransactions();

        // One-step: add the charge AND create the Square card link in the same tap
        // (ETO-style), then hand the office a way to send it.
        $channel = $request->input('create_link');
        if ($channel && in_array($channel, ['email', 'sms', 'whatsapp', 'copy'], true)
            && $data['method'] === 'card' && (float) $payment->amount > 0 && $payment->status !== 'paid') {
            $link = $this->createLink($booking, $payment, (float) $payment->amount);
            if ($link) {
                return $this->shareLink(back(), $booking, (float) $payment->amount, $link, $channel);
            }

            return back()->with('status', 'Transaction added.')
                ->with('error', 'Card payments aren’t set up yet, so no link was created.')->with('scroll', 'transactions');
        }

        return back()->with('status', 'Transaction added — '.$payment->name().' £'.number_format((float) $payment->amount, 2))
            ->with('scroll', 'transactions');
    }

    /** Edit an existing transaction (amount, method, status, name, charge). */
    public function update(Request $request, Booking $booking, Payment $payment): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_unless($payment->booking_id === $booking->id, 404);
        $data = $request->validate($this->rules());

        $payment->forceFill([
            'method' => $data['method'],
            'amount' => $data['amount'],
            'status' => $data['status'],
            'paid_at' => $data['status'] === 'paid' ? ($payment->paid_at ?? now()) : null,
            'meta' => array_merge((array) $payment->meta, array_filter([
                'name' => trim((string) ($data['name'] ?? '')) ?: null,
                'charge' => (float) ($data['charge'] ?? 0) ?: null,
            ], fn ($v) => $v !== null)),
        ])->save();

        $booking->load('payments');
        $booking->reconcilePaymentStatusFromTransactions();

        return back()->with('status', 'Transaction updated.')->with('scroll', 'transactions');
    }

    /** Change just the status inline (the pencil next to "Status: Paid"). */
    public function status(Request $request, Booking $booking, Payment $payment): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_unless($payment->booking_id === $booking->id, 404);
        $data = $request->validate([
            'status' => ['required', Rule::in(['pending', 'link_sent', 'paid', 'balance_remaining', 'failed', 'refunded'])],
        ]);

        $payment->forceFill([
            'status' => $data['status'],
            'paid_at' => $data['status'] === 'paid' ? ($payment->paid_at ?? now()) : null,
        ])->save();

        $booking->load('payments');
        $booking->reconcilePaymentStatusFromTransactions();

        return back()->with('status', 'Transaction marked '.$payment->statusLabel().'.')->with('scroll', 'transactions');
    }

    /** Mark a transaction paid ("Pay now" / cash received). */
    public function payNow(Request $request, Booking $booking, Payment $payment): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_unless($payment->booking_id === $booking->id, 404);

        $payment->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
        $booking->load('payments');
        $booking->reconcilePaymentStatusFromTransactions();

        return back()->with('status', 'Transaction marked paid.')->with('scroll', 'transactions');
    }

    /** Copy a transaction (e.g. to bill the return leg the same way). */
    public function duplicate(Request $request, Booking $booking, Payment $payment): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_unless($payment->booking_id === $booking->id, 404);

        $booking->payments()->create([
            'method' => $payment->method,
            'amount' => $payment->amount,
            'status' => 'pending',
            'meta' => (array) $payment->meta,
        ]);

        return back()->with('status', 'Transaction duplicated.')->with('scroll', 'transactions');
    }

    /** Remove a transaction from the ledger. */
    public function destroy(Request $request, Booking $booking, Payment $payment): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_unless($payment->booking_id === $booking->id, 404);

        $payment->delete();
        $booking->load('payments');
        $booking->reconcilePaymentStatusFromTransactions();

        return back()->with('status', 'Transaction deleted.')->with('scroll', 'transactions');
    }

    /**
     * Generate a card payment link for this transaction's amount and either email
     * it to the customer or hand the office an SMS deep link to send by hand
     * (keeping to the "no paid messaging API" rule). Card only.
     */
    public function sendLink(Request $request, Booking $booking, Payment $payment): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_unless($payment->booking_id === $booking->id, 404);
        $data = $request->validate(['channel' => ['required', Rule::in(['email', 'sms', 'whatsapp', 'copy'])]]);

        $amount = (float) $payment->amount;
        if ($amount <= 0) {
            return back()->with('error', 'Set an amount on the transaction before sending a payment link.')->with('scroll', 'transactions');
        }

        $link = $this->createLink($booking, $payment, $amount);
        if (! $link) {
            return back()->with('error', 'Card payments aren’t set up yet, so no payment link could be created.')->with('scroll', 'transactions');
        }

        return $this->shareLink(back(), $booking, $amount, $link, $data['channel']);
    }

    /**
     * Reset a booking's payment status back to unpaid and clear the "paid"
     * markers — for undoing a full test payment. Admin only. Does NOT refund: if
     * real money was taken, refund it in Square/Stripe. Individual transaction
     * rows are left as they are (manage them in the list).
     */
    public function resetPayment(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $meta = $booking->meta ?? [];
        unset($meta['square_payment'], $meta['confirmed_paid_at'], $meta['vat_paid']);
        $booking->forceFill(['payment_status' => 'pending', 'meta' => $meta])->save();

        return back()
            ->with('status', 'Payment status reset to unpaid and the paid markers cleared. If real money was taken, refund it in Square/Stripe.')
            ->with('scroll', 'transactions');
    }

    /**
     * Create a £1 (or small) TEST card link to prove the live card flow works end
     * to end — admin only. It charges for real through the booking's provider
     * (Stripe for the non-VAT sister, else Square) but uses a TEST- reference, so
     * paying it NEVER marks this or any booking paid — nothing to clean up in the
     * app; just refund the charge in Square/Stripe.
     */
    public function testLink(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $amount = (float) ($request->input('amount', 1));
        $amount = max(0.5, min($amount, 5)); // keep the test tiny (£0.50–£5)
        $entity = $booking->billingEntity();
        $stripe = app(\App\Services\Payments\StripePaymentService::class);

        if ($entity === 'chauffeurs' && $stripe->enabled()) {
            $link = $stripe->createTestCheckoutUrl($amount, route('payments.index'));
            $provider = 'Stripe (PVT LTD)';
        } elseif ($this->square->enabled($entity)) {
            $link = $this->square->createTestCheckoutUrl($entity, $amount, route('payments.index'));
            $provider = 'Square';
        } else {
            return back()->with('error', 'No card provider is connected for this booking yet — add the keys in Settings first.')->with('scroll', 'transactions');
        }

        if (! $link) {
            return back()->with('error', 'Couldn’t create the test link — check the payment keys and try again.')->with('scroll', 'transactions');
        }

        return back()
            ->with('status', '£'.number_format($amount, 2).' TEST link created via '.$provider.' — open it, pay with a real card, then refund it in '.$provider.'. It will NOT mark this booking paid.')
            ->with('copy_link', $link)
            ->with('scroll', 'transactions');
    }

    /**
     * Create the hosted checkout link for a transaction's amount and store it on
     * the transaction. The PROVIDER follows the billing entity: the non-VAT sister
     * company (Central Executive Transfers PVT LTD) is taken via Stripe; the
     * VAT-registered company via Square. Returns the URL, or null if neither is
     * configured for this entity.
     */
    private function createLink(Booking $booking, Payment $payment, float $amount): ?string
    {
        $link = app(\App\Services\Payments\PaymentGateway::class)
            ->createCheckoutUrl($booking, $amount, route('payments.index'), $payment->name());

        if ($link) {
            $payment->forceFill([
                'tide_payment_link' => $link,
                'status' => $payment->status === 'paid' ? 'paid' : 'link_sent',
            ])->save();
        }

        return $link;
    }

    /**
     * Hand the office the link the way they asked: email it, or give a ready-to-send
     * WhatsApp / SMS deep link (keeping the "no paid messaging API" rule), or just
     * the raw link to copy. The link is always copyable from the row too.
     */
    private function shareLink(RedirectResponse $redirect, Booking $booking, float $amount, string $link, string $channel): RedirectResponse
    {
        $redirect->with('copy_link', $link)->with('scroll', 'transactions');

        $body = 'Central Executive Transfers — pay for booking '.$booking->reference.' (£'.number_format($amount, 2).'): '.$link;
        $phone = $booking->customerContactNumber() ?: $booking->customer?->phone;

        if ($channel === 'email') {
            $email = $booking->customer?->email;
            if (! $email) {
                return $redirect->with('error', 'No customer email on file — the link is created, copy it to send another way.');
            }
            try {
                Mail::to($email)->send(new \App\Mail\PaymentLinkMail($booking, $amount, $link));
            } catch (\Throwable $e) {
                Log::warning('[transactions] payment link email failed: '.$e->getMessage());

                return $redirect->with('error', 'Could not email the link — it’s created, copy it to send another way.');
            }

            return $redirect->with('status', 'Payment link emailed to '.$email.'.');
        }

        if ($channel === 'whatsapp') {
            $wa = \App\Support\Phone::wa($phone);

            return $redirect
                ->with('status', $wa ? 'WhatsApp link ready — tap to open the chat with it filled in.' : 'Link created — no mobile on file for WhatsApp, copy it to send.')
                ->with('share_wa', $wa ? 'https://wa.me/'.$wa.'?text='.rawurlencode($body) : null);
        }

        if ($channel === 'sms') {
            $digits = $phone ? preg_replace('/[^0-9+]/', '', $phone) : '';

            return $redirect
                ->with('status', 'SMS link ready — tap to open your SMS app with it filled in.')
                ->with('sms_link', 'sms:'.$digits.'?&body='.rawurlencode($body));
        }

        // copy
        return $redirect->with('status', 'Card payment link created — copy it to send however you like.');
    }
}
