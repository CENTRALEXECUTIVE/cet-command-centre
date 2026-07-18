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
            'enabled' => $this->square->enabled(),
            'driverName' => $booking?->driverPublicName(),
        ]);
    }

    public function pay(Request $request, string $token): RedirectResponse
    {
        $booking = Booking::byTipToken($token);
        if (! $booking) {
            return redirect()->route('tip.show', $token);
        }

        if (! $this->square->enabled()) {
            return back()->with('tipError', 'Card tips aren’t available just yet — sorry about that.');
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:500'],
        ]);

        $url = $this->square->createCheckoutUrl(
            $booking,
            round((float) $data['amount'], 2),
            redirectUrl: route('tip.thanks', $token),
        );

        if (! $url) {
            return back()->with('tipError', 'Sorry — we couldn’t start the payment. Please try again.');
        }

        return redirect()->away($url);
    }

    public function thanks(string $token): View
    {
        $booking = Booking::byTipToken($token);

        return view('tip.thanks', ['driverName' => $booking?->driverPublicName()]);
    }
}
