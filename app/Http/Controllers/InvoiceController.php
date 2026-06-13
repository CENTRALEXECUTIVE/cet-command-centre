<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\View\View;

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
        $user = $request->user();

        if ($user->isCorporateClient()
            && ! $user->corporateAccounts->pluck('id')->contains($invoice->corporate_account_id)) {
            abort(403);
        }

        $invoice->load(['corporateAccount', 'items.booking']);

        return view('invoices.show', compact('invoice'));
    }
}
