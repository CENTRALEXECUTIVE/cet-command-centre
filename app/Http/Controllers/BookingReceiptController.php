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

        $kind = $booking->vatInvoiceRequested() ? 'Invoice' : 'Receipt';
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($pdf->renderReceipt($booking), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "{$disposition}; filename={$kind}-{$booking->reference}.pdf",
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
        $to = $cover ? $cover['email'] : $booking->customer?->email;
        if (! $to) {
            return back()->with('error', $cover
                ? 'No email for '.$cover['name'].' — add one in the cover-job details, then resend.'
                : 'This customer has no email address on file — add one first, then resend.');
        }

        Mail::to($to)->send(new BookingReceiptMail($booking, $pdf->renderReceipt($booking)));

        return back()->with('status', ($cover ? 'Invoice' : 'Receipt').' emailed to '.$to.'.');
    }
}
