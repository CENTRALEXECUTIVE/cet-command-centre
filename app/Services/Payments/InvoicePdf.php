<?php

namespace App\Services\Payments;

use App\Models\Booking;
use App\Models\Invoice;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;

/**
 * Renders a corporate VAT invoice to a PDF using dompdf and stores it on the
 * local disk. The stored path is recorded on the invoice for emailing/download.
 */
class InvoicePdf
{
    public function render(Invoice $invoice): string
    {
        $invoice->loadMissing(['corporateAccount', 'items']);

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4');
        $dompdf->loadHtml(View::make('pdf.invoice', [
            'invoice' => $invoice,
            'company' => \App\Support\InvoiceProfile::company(),
            'bank' => \App\Support\InvoiceProfile::bank(),
            'footerNote' => \App\Support\InvoiceProfile::footerNote(),
        ])->render());
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Render a single-booking receipt (or VAT invoice, for account / VAT-invoice
     * jobs) to a PDF. Reuses the company/bank profile so it matches the corporate
     * invoices. Returns the raw PDF bytes.
     */
    public function renderReceipt(Booking $booking): string
    {
        $booking->loadMissing(['customer', 'vehicleType']);
        $cover = $booking->coverFor();

        // A cover job is an INVOICE to the operator we covered for (they owe CET);
        // a normal job is a receipt/VAT invoice to the customer.
        if ($cover) {
            $gross = $booking->coverForAmount() ?? 0.0;
            $isVat = false;      // simple operator invoice, no VAT split
            $breakdown = null;
            $billedTo = $cover['name'];
            $billedEmail = $cover['email'];
            $paid = false;       // the whole point is that they still owe us
        } else {
            $breakdown = $booking->fareVatBreakdown();
            $gross = $booking->fareGross() ?? 0.0;
            $isVat = $booking->vatInvoiceRequested();
            $billedTo = $booking->displayName();
            $billedEmail = $booking->customer?->email;
            $paid = $booking->fareIsPaid();
        }

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4');
        $dompdf->loadHtml(View::make('pdf.receipt', [
            'booking' => $booking,
            'isCover' => (bool) $cover,
            'isVat' => $isVat,
            'breakdown' => $breakdown,
            'gross' => $gross,
            'paid' => $paid,
            'billedTo' => $billedTo,
            'customerEmail' => $billedEmail,
            'company' => \App\Support\InvoiceProfile::company(),
            'footerNote' => \App\Support\InvoiceProfile::footerNote(),
        ])->render());
        $dompdf->render();

        return $dompdf->output();
    }

    /** Render and store the PDF, returning the storage-relative path. */
    public function store(Invoice $invoice): string
    {
        $path = "invoices/{$invoice->invoice_number}.pdf";
        Storage::disk('local')->put($path, $this->render($invoice));
        $invoice->update(['pdf_path' => $path]);

        return $path;
    }
}
