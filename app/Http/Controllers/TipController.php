<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Services\Payments\SquareTipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public customer TIP page — no login, the token in the URL is the key. The
 * customer picks an amount, we create a Square-hosted checkout and send them to
 * it. Square's webhook logs the paid tip onto the driver's payroll. Always
 * renders a tidy branded page (unknown token / tipping unavailable), never a raw
 * 404.
 */
class TipController extends Controller
{
    public function __construct(private readonly SquareTipService $square) {}

    public function show(string $token): View
    {
        $booking = Booking::byTipToken($token);
        $booking?->load(['driver', 'calendarEvent']);

        return view('tip.show', [
            'booking' => $booking,
            'token' => $token,
            'amounts' => $this->square->presetAmounts(),
            // Card tips are on when the booking's company can take them (PVT LTD → Stripe, Ltd → Square).
            'enabled' => $booking && $booking->billingEntity() === 'chauffeurs' && app(\App\Services\Payments\StripePaymentService::class)->enabled()
                ? true : $this->square->enabled(),
            'driverName' => $booking?->driverPublicName(),
        ]);
    }

    public function pay(Request $request, string $token): RedirectResponse
    {
        $booking = Booking::byTipToken($token);
        if (! $booking) {
            return redirect()->route('tip.show', $token);
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:500'],
        ]);
        $amount = round((float) $data['amount'], 2);

        // Tips follow the company that billed the job (directors, 12 Nov 2026):
        // PVT LTD (no VAT) → Stripe; Ltd (VAT) → Square.
        $stripe = app(\App\Services\Payments\StripePaymentService::class);
        if ($booking->billingEntity() === 'chauffeurs' && $stripe->enabled()) {
            $url = $stripe->createTipCheckoutUrl($booking, $amount, redirectUrl: route('tip.thanks', $token));
        } elseif ($this->square->enabled()) {
            $url = $this->square->createCheckoutUrl($booking, $amount, redirectUrl: route('tip.thanks', $token));
        } else {
            return back()->with('tipError', 'Card tips aren’t available just yet — sorry about that.');
        }

        if (! $url) {
            return back()->with('tipError', 'Sorry — we couldn’t start the payment. Please try again.');
        }

        return redirect()->away($url);
    }

    /**
     * The customer says they already tipped in cash. We take no payment — just
     * note it on the booking (so the office knows to confirm/log the amount with
     * the driver) and show a friendly thank-you. No amount is recorded, because
     * the customer isn't asked for one here.
     */
    public function cash(string $token): RedirectResponse
    {
        Booking::byTipToken($token)?->noteCustomerCashTip();

        return redirect()->route('tip.thanks', ['token' => $token, 'cash' => 1]);
    }

    public function thanks(Request $request, string $token): View
    {
        $booking = Booking::byTipToken($token);

        // A customer who's just tipped is a happy one — invite a Google review,
        // but only once per customer, and never mid-trip (a return pair gets its
        // review after BOTH legs are complete, so tipping on the outbound won't).
        $askReview = $booking
            && app(\App\Services\Messaging\BookingNotifier::class)->canInviteReview($booking);

        return view('tip.thanks', [
            'driverName' => $booking?->driverPublicName(),
            'cash' => $request->boolean('cash'),
            'reviewUrl' => $askReview ? (string) config('cet.review_url') : null,
        ]);
    }
}
