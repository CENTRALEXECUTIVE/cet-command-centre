<?php

namespace App\Services\Payments;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\CorporateAccount;
use App\Models\Invoice;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Generates VAT invoices for corporate account bookings. Completed account
 * journeys in a period that have not yet been invoiced are grouped per account
 * into a single invoice with line items, subtotal, VAT and total.
 */
class InvoiceService
{
    /**
     * Generate one invoice per account for completed, un-invoiced account
     * bookings within the period.
     *
     * @return Collection<int, Invoice>
     */
    public function generateForPeriod(CarbonInterface $start, CarbonInterface $end)
    {
        return CorporateAccount::where('is_active', true)->get()
            ->map(fn (CorporateAccount $account) => $this->generateForAccount($account, $start, $end))
            ->filter()
            ->values();
    }

    public function generateForAccount(CorporateAccount $account, CarbonInterface $start, CarbonInterface $end): ?Invoice
    {
        $bookings = Booking::where('corporate_account_id', $account->id)
            ->where('payment_method', PaymentMethod::Account->value)
            ->where('status', BookingStatus::Complete->value)
            ->where('payment_status', '!=', 'invoiced')
            ->whereBetween('pickup_at', [$start, $end])
            ->get();

        if ($bookings->isEmpty()) {
            return null;
        }

        return DB::transaction(function () use ($account, $bookings, $start, $end) {
            $vatRate = (float) config('cet.vat_rate', 0.20);
            $subtotal = (float) $bookings->sum(fn (Booking $b) => (float) ($b->final_price ?? $b->quoted_price ?? 0));
            $vat = round($subtotal * $vatRate, 2);

            $invoice = Invoice::create([
                'invoice_number' => $this->nextNumber(),
                'corporate_account_id' => $account->id,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'subtotal' => $subtotal,
                'vat_amount' => $vat,
                'total' => round($subtotal + $vat, 2),
                'status' => 'issued',
                'issued_at' => now()->toDateString(),
                'due_at' => now()->addDays($account->payment_terms_days ?? 30)->toDateString(),
            ]);

            foreach ($bookings as $booking) {
                $invoice->items()->create([
                    'booking_id' => $booking->id,
                    'description' => "{$booking->reference} — {$booking->pickup_at->format('d/m/Y H:i')} "
                        ."{$booking->pickup_address} to {$booking->destination_address}",
                    'cost_code' => $booking->cost_code,
                    'amount' => (float) ($booking->final_price ?? $booking->quoted_price ?? 0),
                ]);

                $booking->update(['payment_status' => 'invoiced']);
            }

            return $invoice;
        });
    }

    private function nextNumber(): string
    {
        $year = now()->format('Y');
        $count = Invoice::whereYear('created_at', $year)->count() + 1;

        return sprintf('CET-%s-%04d', $year, $count);
    }
}
