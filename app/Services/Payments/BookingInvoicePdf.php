<?php

namespace App\Services\Payments;

use App\Models\Booking;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\View;

/**
 * Renders a VAT invoice / receipt PDF for a single web booking, in the same
 * shape ETO produced (Bill from / Bill to, a line-item table with net · VAT ·
 * gross, a VAT summary, and the payments + amount-due block) — but with CET's
 * real VAT now the company is registered. Returned as a raw PDF string to attach
 * to the customer's confirmation email.
 */
class BookingInvoicePdf
{
    public function __construct(private readonly VatService $vat) {}

    /** Raw PDF bytes for this booking's invoice. */
    public function render(Booking $booking): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4');
        $dompdf->loadHtml(View::make('pdf.booking-invoice', $this->data($booking))->render());
        $dompdf->render();

        return $dompdf->output();
    }

    /** Everything the invoice view needs, VAT worked out once. */
    public function data(Booking $booking): array
    {
        $booking->loadMissing(['customer', 'vehicleType']);
        $vat = $booking->fareVatBreakdown() ?? ['net' => 0.0, 'vat' => 0.0, 'gross' => 0.0, 'rate' => 0.0];

        $paid = ($booking->payment_status ?? null) === 'paid';
        $paidAmount = $paid ? $vat['gross'] : (float) ($booking->meta['square_payment']['amount'] ?? 0);

        return [
            'company' => (array) config('cet.company'),
            'vatNumber' => $this->vat->number(),
            'reference' => $booking->external_reference ?: $booking->reference,
            'issueDate' => ($booking->created_at ?? now())->format('d/m/Y'),
            'dueDate' => ($booking->pickup_at ?? now())->format('d/m/Y'),
            'customerName' => $booking->displayName() ?: ($booking->customer?->name ?? 'Customer'),
            'customerPhone' => $booking->customer?->phone,
            'customerEmail' => $booking->customer?->email,
            'lines' => [[
                'description' => 'Journey',
                'details' => array_filter([
                    'Reference number' => $booking->external_reference ?: $booking->reference,
                    'Date & time' => $booking->pickup_at?->format('d/m/Y H:i'),
                    'Pickup' => $booking->pickup_address,
                    'Dropoff' => $booking->destination_address,
                    'Vehicle type' => $booking->vehicleType?->name,
                    'Name' => $booking->displayName() ?: $booking->customer?->name,
                ]),
                'qty' => 1,
                'net' => $vat['net'],
                'vat' => $vat['vat'],
                'gross' => $vat['gross'],
                'rate' => $vat['rate'],
            ]],
            'ratePercent' => (int) round(($vat['rate'] ?? 0) * 100),
            'net' => $vat['net'],
            'vatAmount' => $vat['vat'],
            'gross' => $vat['gross'],
            'paid' => $paid,
            'paidAmount' => round($paidAmount, 2),
            'amountDue' => round($vat['gross'] - ($paid ? $vat['gross'] : 0), 2),
        ];
    }
}
