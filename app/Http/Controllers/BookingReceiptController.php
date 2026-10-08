<?php

namespace App\Http\Controllers;

use App\Mail\BookingReceiptMail;
use App\Models\Booking;
use App\Services\Payments\InvoicePdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Response;

/**
 * The per-booking receipt / VAT invoice: the office can view the PDF, download
 * it, or email it to the customer straight from the booking page. Emailing is a
 * deliberate button press — never automatic — so the "nothing auto-sends to
 * customers" rule is respected.
 */
class BookingReceiptController extends Controller
{
    /** Stream the receipt PDF — inline to view, attachment to download. */
    public function show(Request $request, Booking $booking, InvoicePdf $pdf): Response
    {
        abort_unless($request->user()->isAdmin(), 403);

        $kind = 'Invoice';
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($pdf->renderReceipt($booking), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "{$disposition}; filename={$kind}-{$booking->reference}.pdf",
            // Never let the browser serve a stale copy — the receipt is regenerated
            // every time so a price/layout change always shows at once (re-opening
            // the same URL otherwise shows the previously cached PDF).
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * Email the PDF — to the operator we covered for on a cover job (they pay us),
     * otherwise to the customer. Manual, office-initiated only.
     */
    public function email(Request $request, Booking $booking, InvoicePdf $pdf): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $cover = $booking->coverFor();
        // Email the invoice to the BOOKER (who pays) when we have their address,
        // falling back to the booking's customer. Cover jobs go to the operator.
        $to = $cover ? $cover['email'] : ($booking->bookerEmail() ?: $booking->customer?->email);
        if (! $to) {
            return back()->with('error', $cover
                ? 'No email for '.$cover['name'].' — add one in the cover-job details, then resend.'
                : 'This customer has no email address on file — add one first, then resend.');
        }

        Mail::to($to)->send(new BookingReceiptMail($booking, $pdf->renderReceipt($booking)));

        return back()->with('status', 'Invoice emailed to '.$to.'.');
    }

    /** Toggle whether the VAT on a VAT invoice has been received (office confirm). */
    public function vatReceived(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $on = $request->boolean('received');
        $booking->markVatReceived($on);

        return back()->with('status', $on
            ? 'VAT marked as received — the invoice now reads paid in full.'
            : 'VAT marked as outstanding again.');
    }

    /** Combine another booking onto this one's invoice (by reference). */
    public function combine(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate(['reference' => ['required', 'string', 'max:64']]);
        $ref = trim($data['reference']);

        $other = Booking::where('reference', $ref)
            ->orWhere('external_reference', $ref)
            ->first();

        if (! $other) {
            return back()->with('error', "No booking found with reference “{$ref}”.");
        }
        if ($other->is($booking)) {
            return back()->with('error', "That's this same booking — pick a different one.");
        }

        $booking->addToInvoiceGroup($other);

        return back()->with('status', "Booking {$other->reference} added to this invoice.");
    }

    /** Remove a booking from this one's combined invoice. */
    public function uncombine(Request $request, Booking $booking, Booking $other): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $booking->removeFromInvoiceGroup($other);

        return back()->with('status', "Booking {$other->reference} removed from this invoice.");
    }
}
