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
        $data = $request->validate(['channel' => ['required', Rule::in(['email', 'sms'])]]);

        $amount = (float) $payment->amount;
        if ($amount <= 0) {
            return back()->with('error', 'Set an amount on the transaction before sending a payment link.')->with('scroll', 'transactions');
        }

        $link = $this->square->enabled($booking->billingEntity())
            ? $this->square->createCheckoutUrl($booking, $amount, route('payments.index'))
            : null;

        if (! $link) {
            return back()->with('error', 'Card payments aren’t set up yet, so no payment link could be created.')->with('scroll', 'transactions');
        }

        $payment->forceFill([
            'tide_payment_link' => $link,
            'status' => $payment->status === 'paid' ? 'paid' : 'link_sent',
        ])->save();

        if ($data['channel'] === 'email') {
            $email = $booking->customer?->email;
            if (! $email) {
                return back()->with('error', 'No customer email on file to send the payment link to.')->with('scroll', 'transactions');
            }
            try {
                Mail::to($email)->send(new \App\Mail\PaymentLinkMail($booking, $amount, $link));
            } catch (\Throwable $e) {
                Log::warning('[transactions] payment link email failed: '.$e->getMessage());

                return back()->with('error', 'Could not send the payment-link email — please try again.')->with('scroll', 'transactions');
            }

            return back()->with('status', 'Payment link emailed to '.$email.'.')->with('scroll', 'transactions');
        }

        // SMS: no paid gateway — hand the office an sms: deep link to send by hand.
        $phone = $booking->customerContactNumber() ?: $booking->customer?->phone;
        $body = 'Central Executive Transfers — pay for booking '.$booking->reference.' (£'.number_format($amount, 2).'): '.$link;
        $smsLink = 'sms:'.($phone ? preg_replace('/[^0-9+]/', '', $phone) : '').'?&body='.rawurlencode($body);

        return back()
            ->with('status', 'Payment link ready — tap to open your SMS app with it filled in.')
            ->with('sms_link', $smsLink)
            ->with('copy_link', $link)
            ->with('scroll', 'transactions');
    }
}
