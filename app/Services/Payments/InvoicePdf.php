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
            'logo' => $this->logoDataUri(),
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
            'customerPhone' => $cover ? ($cover['phone'] ?? null) : $booking->customerContactNumber(),
            'invoiceNumber' => $booking->external_reference ?: $booking->reference,
            'issueDate' => now(),
            'paymentDue' => now()->addDays(14),
            'company' => \App\Support\InvoiceProfile::company(),
            'footerNote' => \App\Support\InvoiceProfile::footerNote(),
        ])->render());
        $dompdf->render();

        return $dompdf->output();
    }

    /** The CET logo as a base64 data URI for the PDF (embedded, no remote fetch). */
    private function logoDataUri(): ?string
    {
        foreach (['images/cet-logo.jpg', 'images/cet-logo.png'] as $rel) {
            $path = public_path($rel);
            if (is_file($path) && ($data = @file_get_contents($path)) !== false) {
                $mime = str_ends_with($rel, '.png') ? 'image/png' : 'image/jpeg';

                return 'data:'.$mime.';base64,'.base64_encode($data);
            }
        }

        return null;
    }

    /**
     * One journey's full detail block for the invoice line — itemised like the ETO
     * invoice (reference, date, pickup, drop-off, vehicle, passengers, flight,
     * meet & greet), each on its own line. Returns "Label: value" lines.
     */
    private function legDetail(Booking $b): string
    {
        $rows = [
            'Reference' => $b->external_reference ?: $b->reference,
            'Date & time' => $b->pickup_at?->format('d/m/Y H:i'),
            'Pickup' => trim((string) $b->displayPickupAddress()),
            'Drop-off' => trim((string) $b->displayDropoffAddress()),
            'Vehicle' => $b->vehicleType?->name,
            'Passengers' => $b->passengerCount(),
            'Flight' => $b->displayFlightNumber() ?: null,
            'Meet & Greet' => $b->displayMeetAndGreet() ?: null,
        ];

        return collect($rows)
            ->filter(fn ($v) => $v !== null && $v !== '')
            ->map(fn ($v, $k) => $k.': '.$v)
            ->implode("\n");
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
