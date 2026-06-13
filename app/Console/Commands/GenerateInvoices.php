<?php

namespace App\Console\Commands;

use App\Services\Payments\InvoiceService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Generates VAT invoices for completed corporate account bookings. Defaults to
 * the previous calendar month; pass --month=YYYY-MM to target a specific month.
 */
class GenerateInvoices extends Command
{
    protected $signature = 'cet:generate-invoices {--month= : Target month as YYYY-MM (defaults to last month)}';

    protected $description = 'Generate monthly VAT invoices for corporate account bookings';

    public function handle(InvoiceService $invoices): int
    {
        $month = $this->option('month')
            ? Carbon::createFromFormat('Y-m', $this->option('month'))->startOfMonth()
            : now()->subMonthNoOverflow()->startOfMonth();

        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $generated = $invoices->generateForPeriod($start, $end);

        $this->info("Generated {$generated->count()} invoice(s) for {$start->format('F Y')}.");

        return self::SUCCESS;
    }
}
