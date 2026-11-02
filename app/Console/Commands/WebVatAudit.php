<?php

namespace App\Console\Commands;

use App\Models\Booking;
use Illuminate\Console\Command;

/**
 * Double-VAT repair for WEBSITE bookings that asked for a VAT invoice.
 *
 * Prices are stored EX-VAT (Booking::fareVatBreakdown adds 20% on top for a VAT
 * invoice). Until Nov 2026 the widget stored the VAT-INCLUSIVE price instead, so the
 * pay link added VAT a second time (£126 shown → £151.20 charged). New bookings carry
 * meta.price_ex_vat; this finds the older ones.
 *
 *   - UNPAID, no office price override → --apply stores the correct net (stored ÷ 1.2),
 *     so the pay link charges what the customer was shown.
 *   - PAID → never changed; listed with the over-charge so the office can refund it.
 *   - Office price set (final_price) or account jobs → listed only, for a human check.
 *
 * Reports by default; pass --apply to fix the unpaid ones. Never touches the calendar.
 *
 * Usage:  php artisan cet:web-vat-audit           (report only)
 *         php artisan cet:web-vat-audit --apply   (fix unpaid)
 */
class WebVatAudit extends Command
{
    protected $signature = 'cet:web-vat-audit {--apply : correct the unpaid ones (otherwise report only)}';

    protected $description = 'Find/repair web VAT-invoice bookings stored with VAT included (double-VAT pay links)';

    public function handle(): int
    {
        $rate = (float) app(\App\Services\Payments\VatService::class)->rate();
        $apply = (bool) $this->option('apply');

        $bookings = Booking::query()
            ->where('source', 'web')
            ->whereNotNull('quoted_price')
            ->get()
            ->filter(fn (Booking $b) => $b->vatInvoiceRequested()
                && empty($b->meta['price_ex_vat'])
                && ($b->payment_method?->value ?? null) !== 'account');

        if ($bookings->isEmpty()) {
            $this->info('No affected web VAT bookings — all good.');

            return self::SUCCESS;
        }

        $fixed = 0;
        $refunds = [];
        $seenGroups = [];
        foreach ($bookings as $b) {
            $stored = (float) $b->quoted_price;            // what the customer was SHOWN (inc VAT)
            $net = round($stored / (1 + $rate), 2);         // what should have been stored
            $line = sprintf('%s  %s  shown £%.2f', $b->reference,
                optional($b->pickup_at)->format('D d M, H:i') ?? '—', $stored);

            if ($b->fareIsPaid()) {
                // Judge a return as ONE trip (the pay link charged both legs in one go).
                $group = $b->invoiceGroupBookings();
                $group = $group->isNotEmpty() ? $group : collect([$b]);
                $key = $group->pluck('id')->sort()->implode('-');
                if (isset($seenGroups[$key])) {
                    continue;
                }
                $seenGroups[$key] = true;

                $shown = round($group->sum(fn (Booking $l) => (float) $l->quoted_price), 2);
                $paid = round($group->sum(fn (Booking $l) => $this->moneyTaken($l)), 2);
                $over = round($paid - $shown, 2);
                $refs = $group->pluck('reference')->implode(' + ');
                $this->warn("PAID     {$refs}  shown £".number_format($shown, 2).'  taken £'.number_format($paid, 2)
                    .($over > 0.009 ? '  → OVER-CHARGED £'.number_format($over, 2).' (refund)' : '  (no over-charge)'));
                if ($over > 0.009) {
                    $refunds[] = [$refs, $over];
                }

                continue;
            }
            if ($b->final_price !== null) {
                $this->line("CHECK    {$line}  office price £".number_format((float) $b->final_price, 2).' set — check it by hand');

                continue;
            }

            $this->line(($apply ? 'FIXED    ' : 'WOULD FIX').' '.$line.'  → store net £'.number_format($net, 2)
                .' (pay link £'.number_format(round($net * (1 + $rate), 2), 2).')');
            if ($apply) {
                $b->forceFill([
                    'quoted_price' => $net,
                    'meta' => array_merge($b->meta ?? [], ['price_ex_vat' => true, 'vat_double_fixed_at' => now()->toIso8601String()]),
                ])->save();
                $fixed++;
            }
        }

        if ($refunds) {
            $this->newLine();
            $this->warn('Refund in Stripe/Square: '.collect($refunds)->map(fn ($r) => $r[0].' £'.number_format($r[1], 2))->implode(', '));
        }
        $this->info($apply ? "Fixed {$fixed} unpaid booking(s)." : 'Report only — re-run with --apply to fix the unpaid ones.');

        return self::SUCCESS;
    }

    /**
     * Money actually taken on one leg: paid ledger rows, else the provider charge
     * recorded on the booking. A return's sibling leg is stamped by the cascade with an
     * id ending "-L{id}" — that's the same payment, so it isn't counted twice.
     */
    private function moneyTaken(Booking $leg): float
    {
        if ($leg->payments->isNotEmpty()) {
            return (float) $leg->payments->where('status', 'paid')->sum('amount');
        }
        $sq = $leg->meta['square_payment'] ?? null;
        if (! is_array($sq) || str_contains((string) ($sq['id'] ?? ''), '-L')) {
            return 0.0;
        }

        return (float) ($sq['amount'] ?? 0);
    }
}
