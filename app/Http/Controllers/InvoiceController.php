<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\Payments\InvoicePdf;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corporate VAT invoices. Admins see all; corporate clients see only their own
 * account's invoices (Phase 4 portal foundation).
 */
class InvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        $query = Invoice::with('corporateAccount')->orderByDesc('issued_at');

        if ($user->isCorporateClient()) {
            $query->whereIn('corporate_account_id', $user->corporateAccounts->pluck('id'));
        }

        return view('invoices.index', ['invoices' => $query->paginate(20)]);
    }

    public function show(Request $request, Invoice $invoice): View
    {
        $this->authoriseAccess($request, $invoice);
        $invoice->load(['corporateAccount', 'items.booking']);

        return view('invoices.show', compact('invoice'));
    }

    public function download(Request $request, Invoice $invoice, InvoicePdf $pdf): Response
    {
        $this->authoriseAccess($request, $invoice);

        return response($pdf->render($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=Invoice-{$invoice->invoice_number}.pdf",
        ]);
    }

    private function authoriseAccess(Request $request, Invoice $invoice): void
    {
        $user = $request->user();
        if ($user->isCorporateClient()
            && ! $user->corporateAccounts->pluck('id')->contains($invoice->corporate_account_id)) {
            abort(403);
        }
    }
}
