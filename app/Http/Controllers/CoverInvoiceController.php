<?php

namespace App\Http\Controllers;

use App\Mail\BookingReceiptMail;
use App\Models\Booking;
use App\Services\Payments\InvoicePdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Combined cover-job invoices: when we've covered several jobs for another
 * operator, bill them all on ONE invoice with a single total instead of sending
 * one per job. Lists cover jobs grouped by operator; the office ticks the ones to
 * include and gets a single PDF (download or email).
 */
class CoverInvoiceController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        $groups = Booking::query()
            ->whereNotNull('meta->cover_for')
            ->with('vehicleType')
            ->orderBy('pickup_at')
            ->get()
            ->filter(fn (Booking $b) => $b->isCoverJob())
            ->groupBy(fn (Booking $b) => $b->coverFor()['name'])
            ->sortKeys();

        return view('invoices.cover', ['groups' => $groups]);
    }

    /**
     * The operators we've covered for before, with their saved email/phone — so
     * the "cover job" form can offer them as a pick list instead of retyping the
     * same operator on every job. The most recent non-empty contact details win.
     */
    public function operators(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $operators = [];
        Booking::query()
            ->whereNotNull('meta->cover_for')
            ->orderBy('pickup_at') // oldest first so newer details overwrite
            ->get()
            ->each(function (Booking $b) use (&$operators) {
                $c = $b->coverFor();
                $name = trim($c['name'] ?? '');
                if ($name === '') {
                    return;
                }
                $key = mb_strtolower($name);
                $operators[$key] = [
                    'name' => $name,
                    // Keep the last known non-empty contact detail for this operator.
                    'email' => filled($c['email'] ?? null) ? $c['email'] : ($operators[$key]['email'] ?? null),
                    'phone' => filled($c['phone'] ?? null) ? $c['phone'] : ($operators[$key]['phone'] ?? null),
                ];
            });

        return response()->json(['operators' => array_values(collect($operators)->sortBy('name')->all())]);
    }

    /** Combined invoice PDF for the chosen cover jobs (download/view). */
    public function pdf(Request $request, InvoicePdf $pdf): Response
    {
        abort_unless($request->user()->isAdmin(), 403);
        $bookings = $this->selected($request);

        return response($pdf->renderCombinedCoverInvoice($bookings), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline')
                .'; filename=Cover-Invoice-'.now()->format('Ymd').'.pdf',
        ]);
    }

    /** Email the combined invoice to the operator. */
    public function email(Request $request, InvoicePdf $pdf): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $bookings = $this->selected($request);
        $operator = $bookings->first()->coverFor();

        if (! ($operator['email'] ?? null)) {
            return back()->with('error', 'No email saved for '.$operator['name'].' — add one on a booking first.');
        }

        Mail::to($operator['email'])->send(
            new BookingReceiptMail($bookings->first(), $pdf->renderCombinedCoverInvoice($bookings))
        );

        return back()->with('status', 'Combined invoice ('.$bookings->count().' jobs) emailed to '.$operator['email'].'.');
    }

    /** The chosen cover-job bookings, all for the SAME operator. */
    private function selected(Request $request)
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer']]);

        $bookings = Booking::whereIn('id', $data['ids'])->with('vehicleType')->orderBy('pickup_at')->get()
            ->filter(fn (Booking $b) => $b->isCoverJob())
            ->values();
        abort_if($bookings->isEmpty(), 422, 'No cover jobs selected.');

        // Keep to one operator per invoice (match the first selection's operator).
        $operator = $bookings->first()->coverFor()['name'];

        return $bookings->filter(fn (Booking $b) => $b->coverFor()['name'] === $operator)->values();
    }
}
