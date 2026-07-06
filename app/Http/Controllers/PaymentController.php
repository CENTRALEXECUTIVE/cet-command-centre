<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Money owed to CET at a glance. Reflects the house rule that a cash job which
 * has RUN is treated as collected on the day — so "outstanding" is really:
 *  - card / account jobs that have run but aren't marked paid (chase these), and
 *  - upcoming cash jobs the driver still has to collect.
 */
class PaymentController extends Controller
{
    private const TERMINAL_OUT = [BookingStatus::Cancelled->value, BookingStatus::NoShow->value];

    private function amount(Booking $b): float
    {
        return (float) ($b->final_price ?? $b->quoted_price ?? 0);
    }

    public function index(): View
    {
        // Owed to us: ran already, not paid, and paid by card or on account.
        $owed = Booking::with(['customer', 'vehicleType'])
            ->whereNotIn('status', self::TERMINAL_OUT)
            ->where('payment_status', '!=', 'paid')
            ->whereIn('payment_method', ['card', 'account'])
            ->where('pickup_at', '<', now())
            ->orderBy('pickup_at')
            ->get();

        // Cash the drivers still have to collect on upcoming jobs.
        $toCollect = Booking::with(['customer', 'vehicleType', 'driver'])
            ->whereNotIn('status', self::TERMINAL_OUT)
            ->where('payment_method', 'cash')
            ->where('payment_status', '!=', 'paid')
            ->where('pickup_at', '>=', now())
            ->orderBy('pickup_at')
            ->get();

        return view('payments.index', [
            'owed' => $owed,
            'toCollect' => $toCollect,
            'owedTotal' => $owed->sum(fn (Booking $b) => $this->amount($b)),
            'collectTotal' => $toCollect->sum(fn (Booking $b) => $this->amount($b)),
        ]);
    }

    /** Mark a booking (and any of its pending payments) as paid. */
    public function markPaid(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $booking->update(['payment_status' => 'paid']);
        $booking->payments()->where('status', '!=', 'paid')
            ->update(['status' => 'paid', 'paid_at' => now()]);

        return back()->with('status', "Booking {$booking->reference} marked as paid.");
    }
}
