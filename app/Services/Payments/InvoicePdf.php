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
        $vat = app(\App\Services\Payments\VatService::class);
        $isVat = ! $cover && $booking->vatInvoiceRequested();
        $rate = $isVat ? $vat->rate() : 0.0;

        // Which journeys this invoice covers — the booking plus its paired return
        // leg (so a return trip reads as two lines, like the office's own invoices).
        $legs = collect([$booking]);
        if ($booking->linked_booking_id && ($partner = Booking::find($booking->linked_booking_id))) {
            $partner->loadMissing(['customer', 'vehicleType']);
            $legs->push($partner);
        }
        $legs = $legs->unique('id')->sortBy('pickup_at')->values();

        // Build a net/VAT/total line for each journey.
        $lines = $legs->map(function (Booking $b) use ($legs, $rate) {
            $net = (float) ($b->fareGross() ?? 0);
            $vatAmt = round($net * $rate, 2);
            $isReturn = $legs->count() > 1 && ($b->is_return_leg || ($legs->count() > 1 && $legs->last()->is($b)));
            $title = $legs->count() > 1 ? ($isReturn ? 'Return transfer' : 'Outbound transfer') : 'Transfer';

            return [
                'title' => $title,
                'detail' => $this->legDetail($b),
                'net' => $net,
                'vat' => $vatAmt,
                'total' => round($net + $vatAmt, 2),
                'paid' => $b->fareIsPaid(),
            ];
        })->all();

        $netTotal = round(collect($lines)->sum('net'), 2);
        $vatTotal = round(collect($lines)->sum('vat'), 2);
        $grossTotal = round($netTotal + $vatTotal, 2);
        // Paid legs are counted as having paid their NET (the standard price); the
        // VAT is then the outstanding balance on a VAT invoice.
        $paymentsReceived = round(collect($lines)->filter(fn ($l) => $l['paid'])->sum('net'), 2);

        if ($cover) {
            // A cover job is a simple invoice to the operator for the agreed amount.
            $amount = (float) ($booking->coverForAmount() ?? 0);
            $lines = [[
                'title' => 'Cover transfer',
                'detail' => $this->legDetail($booking),
                'net' => $amount, 'vat' => 0.0, 'total' => $amount, 'paid' => false,
            ]];
            $netTotal = $amount;
            $vatTotal = 0.0;
            $grossTotal = $amount;
            $paymentsReceived = 0.0;
            $billedTo = $cover['name'];
            $billedEmail = $cover['email'];
        } else {
            $billedTo = $booking->customer?->corporateAccount?->name ?: $booking->displayName();
            $billedEmail = $booking->customer?->email;
        }

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4');
        $dompdf->loadHtml(View::make('pdf.receipt', [
            'booking' => $booking,
            'isCover' => (bool) $cover,
            'isVat' => $isVat,
            'ratePercent' => (int) round($rate * 100),
            'lines' => $lines,
            'netTotal' => $netTotal,
            'vatTotal' => $vatTotal,
            'grossTotal' => $grossTotal,
            'paymentsReceived' => $paymentsReceived,
            'balanceDue' => round($grossTotal - $paymentsReceived, 2),
            'attn' => $cover ? null : $booking->displayName(),
            'billedTo' => $billedTo,
            'customerEmail' => $billedEmail,
            'company' => \App\Support\InvoiceProfile::company(),
            'footerNote' => \App\Support\InvoiceProfile::footerNote(),
        ])->render());
        $dompdf->render();

        return $dompdf->output();
    }

    /** One journey's detail line for the invoice table. */
    private function legDetail(Booking $b): string
    {
        $when = $b->pickup_at?->format('d/m/Y H:i');
        $route = trim((string) $b->displayPickupAddress()).' to '.trim((string) $b->displayDropoffAddress());
        $extras = array_filter([
            $b->vehicleType?->name ? $b->vehicleType->name.' vehicle' : null,
            $b->displayFlightNumber() ? 'Flight '.$b->displayFlightNumber() : null,
            $b->external_reference ? 'Ref '.$b->external_reference : ($b->reference ? 'Ref '.$b->reference : null),
        ]);

        return trim(($when ? $when.' - ' : '').$route)."\n".implode(' | ', $extras);
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
