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
    /** Absolute path to the bundled Inter TTFs used by the invoice template. */
    private function fontPath(): string
    {
        return base_path('resources/fonts');
    }

    /**
     * A dompdf configured to embed our bundled Inter font: a writable cache dir
     * under storage (shared hosting's vendor dir isn't writable) and a chroot that
     * lets it read the TTFs from resources/fonts.
     */
    private function makeDompdf(): Dompdf
    {
        $cache = storage_path('app/dompdf-fonts');
        if (! is_dir($cache)) {
            @mkdir($cache, 0775, true);
        }

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->setChroot([base_path(), sys_get_temp_dir(), $cache]);
        $options->set('fontDir', $cache);
        $options->set('fontCache', $cache);

        return new Dompdf($options);
    }

    public function render(Invoice $invoice): string
    {
        $invoice->loadMissing(['corporateAccount', 'items']);

        $dompdf = $this->makeDompdf();
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
    /**
     * One combined invoice to an operator for SEVERAL cover jobs — a line per
     * booking with its own amount, then a grand total. Used when we've covered
     * multiple jobs for another company and bill them all on one invoice.
     *
     * @param  \Illuminate\Support\Collection<int, Booking>  $bookings
     */
    public function renderCombinedCoverInvoice($bookings): string
    {
        $bookings = $bookings->values();
        $first = $bookings->first();
        $operator = $first?->coverFor();

        $lines = $bookings->map(function (Booking $b) {
            $b->loadMissing('vehicleType');
            $amount = (float) ($b->coverForAmount() ?? 0);

            return ['title' => 'Cover transfer', 'detail' => $this->legDetail($b),
                'net' => $amount, 'vat' => 0.0, 'total' => $amount, 'paid' => false];
        })->all();

        $total = round(collect($lines)->sum('total'), 2);

        $dompdf = $this->makeDompdf();
        $dompdf->setPaper('A4');
        $dompdf->loadHtml(View::make('pdf.receipt', [
            'booking' => $first,
            'logo' => $this->logoDataUri(),
            'fontDir' => $this->fontPath(),
            'isCover' => true,
            'isVat' => false,
            'ratePercent' => 0,
            'lines' => $lines,
            'netTotal' => $total,
            'vatTotal' => 0.0,
            'grossTotal' => $total,
            'paymentsReceived' => 0.0,
            'balanceDue' => $total,
            'attn' => null,
            'billedTo' => $operator['name'] ?? 'Operator',
            'customerEmail' => $operator['email'] ?? null,
            'customerPhone' => $operator['phone'] ?? null,
            'invoiceNumber' => 'CVR-'.now()->format('ymd-Hi'),
            'issueDate' => now(),
            'paymentDue' => now()->addDays(14),
            'company' => \App\Support\InvoiceProfile::company(),
            'footerNote' => \App\Support\InvoiceProfile::footerNote(),
        ])->render());
        $dompdf->render();

        return $dompdf->output();
    }

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
            // Bill the BOOKER (who paid), not the lead passenger. A corporate
            // account is still billed to the company. The lead passenger, when
            // different, appears as "Attn:" via $attn below. Contact details are the
            // booker's too (not the passenger's) so the invoice reaches who pays.
            $billedTo = $booking->customer?->corporateAccount?->name ?: $booking->bookerName();
            $billedEmail = $booking->bookerEmail();
        }

        $dompdf = $this->makeDompdf();
        $dompdf->setPaper('A4');
        $dompdf->loadHtml(View::make('pdf.receipt', [
            'booking' => $booking,
            'logo' => $this->logoDataUri(),
            'fontDir' => $this->fontPath(),
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
            'customerPhone' => $cover ? ($cover['phone'] ?? null) : $booking->bookerPhone(),
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
