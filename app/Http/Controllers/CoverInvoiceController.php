<?php

namespace App\Http\Controllers;

use App\Mail\BookingReceiptMail;
use App\Models\Booking;
use App\Services\Payments\InvoicePdf;
use App\Services\Payments\SquareBookingPaymentService;
use App\Services\Payments\StripePaymentService;
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

        // Show settled (paid) cover jobs too only when asked — by default they drop
        // off so they can't be billed again on a future invoice.
        $showPaid = $request->boolean('show_paid');

        $all = Booking::query()
            ->whereNotNull('meta->cover_for')
            ->with('vehicleType')
            ->orderBy('pickup_at')
            ->get()
            ->filter(fn (Booking $b) => $b->isCoverJob());

        $paidCount = $all->filter(fn (Booking $b) => $b->isCoverPaid())->count();

        $groups = $all
            ->filter(fn (Booking $b) => $showPaid ? true : ! $b->isCoverPaid())
            ->groupBy(fn (Booking $b) => $b->coverFor()['name'])
            ->sortKeys();

        return view('invoices.cover', [
            'groups' => $groups,
            'showPaid' => $showPaid,
            'paidCount' => $paidCount,
        ]);
    }

    /**
     * Mark (or reopen) cover jobs as paid. Used when the operator settles an
     * invoice — by card link (auto, via the webhook) or by cash/bank transfer the
     * office records here. A paid job drops off the list so it can't be attached to
     * another invoice. Pass paid=0 to reopen a job marked paid by mistake.
     */
    public function markPaid(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);
        // Reopen when paid=0 is sent; otherwise this is a manual "they've settled it"
        // mark (cash/bank transfer). Card payments auto-settle via the webhook.
        $markPaid = ! $request->has('paid') || $request->boolean('paid');

        $bookings = Booking::whereIn('id', $data['ids'])->get()
            ->filter(fn (Booking $b) => $b->isCoverJob())
            ->values();
        abort_if($bookings->isEmpty(), 422, 'No cover jobs selected.');

        foreach ($bookings as $b) {
            if ($markPaid) {
                $b->markCoverPaid([
                    'provider' => 'manual',
                    'reference' => $b->meta['cover_payment_link']['reference'] ?? null,
                ]);
            } else {
                $b->clearCoverPaid();
            }
        }

        $n = $bookings->count();
        $verb = $markPaid ? 'marked paid' : 'reopened';

        return back()->with('status', $n.' cover job'.($n === 1 ? '' : 's').' '.$verb.'.');
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

        // A distinct, human-readable filename per operator (and its invoice number
        // if a link's been created), so downloads don't all land as the same name.
        $anchor = $bookings->first();
        $operatorName = $anchor->coverFor()['name'] ?? 'Operator';
        $ref = $anchor->meta['cover_payment_link']['reference'] ?? ('CVR-'.now()->format('ymdHi'));
        $filename = 'Cover-Invoice-'.\Illuminate\Support\Str::slug($operatorName).'-'.$ref.'.pdf';

        return response($pdf->renderCombinedCoverInvoice($bookings, $this->coverIsPvt($request)), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline')
                .'; filename='.$filename,
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

        // The PVT decision for BOTH the invoice (company name) and the sender: an
        // explicit provider on the request, else the stored payment-link provider
        // (a Stripe link => non-VAT / PVT LTD).
        $pvt = $this->coverIsPvt($request)
            ?? (($bookings->first()->meta['cover_payment_link']['provider'] ?? null) === 'stripe');

        Mail::to($operator['email'])->send(
            new BookingReceiptMail($bookings->first(), $pdf->renderCombinedCoverInvoice($bookings, $pvt), $pvt)
        );

        return back()->with('status', 'Combined invoice ('.$bookings->count().' jobs) emailed to '.$operator['email'].'.');
    }

    /**
     * Create a card-payment link for the combined cover total and stash it on the
     * anchor booking, so the View/Download/Email invoice all carry a "Pay online"
     * link with the amount and reference. The operator can then pay the whole
     * invoice by card.
     *
     * Provider follows VAT status, the same split as every other CET card payment:
     * a VAT cover invoice is billed by Central Executive Transfers Ltd via SQUARE
     * (the default); a non-VAT cover invoice is billed by the sister company PVT
     * LTD via STRIPE (`provider=stripe`). Either way the link is COVER-tagged so
     * it never marks an individual booking's fare paid — the office reconciles
     * cover payments in the provider.
     */
    public function paymentLink(Request $request, SquareBookingPaymentService $square, StripePaymentService $stripe): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $wantsJson = $request->wantsJson() || $request->ajax();
        $fail = fn (string $msg) => $wantsJson
            ? response()->json(['ok' => false, 'error' => $msg], 200)
            : back()->with('error', $msg);

        $provider = $request->input('provider') === 'stripe' ? 'stripe' : 'square';

        $bookings = $this->selected($request);
        $anchor = $bookings->first();
        $operator = $anchor->coverFor();
        $total = round($bookings->sum(fn (Booking $b) => (float) ($b->coverForAmount() ?? 0)), 2);

        if ($total <= 0) {
            return $fail('The selected cover jobs total £0 — set an amount on them first.');
        }

        // A short, stable reference the operator and the provider both show.
        $reference = 'CVR-'.strtoupper(\Illuminate\Support\Str::slug($operator['name'] ?? 'operator', '')).'-'.now()->format('ymdHi');
        $label = 'Cover transfers ('.$bookings->count().') for '.($operator['name'] ?? 'operator');

        if ($provider === 'stripe') {
            if (! $stripe->enabled()) {
                return $fail('Stripe isn’t connected yet, so a non-VAT (PVT LTD) card link can’t be created. Add the Stripe keys in Settings → payments.');
            }
            $url = $stripe->createCoverCheckoutUrl($total, $reference, $label);
            if (! $url) {
                return $fail('Couldn’t create the Stripe payment link — check the Stripe connection and try again.');
            }
        } else {
            if (! $square->enabled('transfers')) {
                return $fail('Square isn’t connected yet, so a card link can’t be created.');
            }
            $url = $square->createCoverCheckoutUrl($total, $reference, $label);
            if (! $url) {
                return $fail('Couldn’t create the payment link — check the Square connection and try again.');
            }
        }

        $anchor->forceFill(['meta' => array_merge($anchor->meta ?? [], [
            'cover_payment_link' => [
                'url' => $url,
                'provider' => $provider,
                'reference' => $reference,
                'amount' => $total,
                'ids' => $bookings->pluck('id')->all(),
                'created_at' => now()->toIso8601String(),
            ],
        ])])->save();

        if ($wantsJson) {
            return response()->json([
                'ok' => true,
                'url' => $url,
                'reference' => $reference,
                'amount' => number_format($total, 2),
            ]);
        }

        return back()
            ->with('status', 'Payment link created for £'.number_format($total, 2).' ('.$reference.') — it’s now on the invoice. Open View/Download/Email to send it.')
            ->with('cover_payment_link', $url);
    }

    /**
     * Whether the cover invoice should be billed by the non-VAT sister company
     * (PVT LTD): true/false when the page says so explicitly (provider=stripe /
     * square), or null to let the PDF follow the payment link already created for
     * this invoice (a Stripe link => PVT LTD).
     */
    private function coverIsPvt(Request $request): ?bool
    {
        if (! $request->has('provider')) {
            return null;
        }

        return $request->input('provider') === 'stripe';
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
