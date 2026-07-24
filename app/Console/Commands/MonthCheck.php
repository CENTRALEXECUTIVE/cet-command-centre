<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Read-only reconciliation for a month: how many jobs ran vs are still to come,
 * how many were cancelled/no-show, and whether any duplicates are inflating the
 * count. Because the bookings mirror the Google Calendar (the 5-minute refresh),
 * these figures should match the calendar for the month. Touches nothing —
 * reads the database only, never the calendar.
 *
 *   php artisan cet:month-check              # this month
 *   php artisan cet:month-check --month=2026-07
 */
class MonthCheck extends Command
{
    protected $signature = 'cet:month-check {--month= : YYYY-MM (defaults to this month)}';

    protected $description = 'Reconcile a month: completed vs upcoming vs cancelled, and flag duplicates';

    public function handle(): int
    {
        $month = $this->option('month');
        $start = ($month ? Carbon::createFromFormat('Y-m', $month, config('app.timezone')) : now())->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $all = Booking::whereBetween('pickup_at', [$start, $end])
            ->with('customer:id,phone,name')
            ->get();

        $cancelled = $all->where('status', BookingStatus::Cancelled)->count();
        $noShow = $all->where('status', BookingStatus::NoShow)->count();

        $live = $all->filter(fn (Booking $b) => ! in_array($b->status, [BookingStatus::Cancelled, BookingStatus::NoShow], true));
        $completed = $live->filter(fn (Booking $b) => $b->pickup_at && $b->pickup_at->lte(now()));
        $upcoming = $live->filter(fn (Booking $b) => $b->pickup_at && $b->pickup_at->gt(now()));

        $this->line('');
        $this->info($start->format('F Y').' — booking reconciliation (read-only)');
        $this->line(str_repeat('─', 48));
        $this->line(sprintf('  Live jobs (excl. cancelled/no-show): %4d', $live->count()));
        $this->line(sprintf('  Completed (already ran):            %4d   ← Review "trips completed"', $completed->count()));
        $this->line(sprintf('  Upcoming (still to come):           %4d', $upcoming->count()));
        $this->line(sprintf('  Cancelled:                          %4d', $cancelled));
        $this->line(sprintf('  No-show:                            %4d', $noShow));
        $this->line(sprintf('  TOTAL calendar events for month:    %4d', $all->count()));

        // Duplicates that would inflate the count.
        $refDupes = $all
            ->filter(fn (Booking $b) => filled(trim((string) $b->external_reference)))
            ->groupBy(fn (Booking $b) => strtoupper(trim((string) $b->external_reference)))
            ->filter(fn ($g) => $g->count() > 1);

        $journeyDupes = $all
            ->filter(fn (Booking $b) => $b->journeySignature() !== null)
            ->groupBy(fn (Booking $b) => $b->journeySignature())
            ->filter(function ($g) {
                if ($g->count() < 2) {
                    return false;
                }
                // Skip groups that are already listed as an exact-reference
                // duplicate (all share the one non-empty reference); flag every
                // other same-journey group — including two different references,
                // which is exactly what inflates the count vs the calendar.
                $refs = $g->map(fn (Booking $b) => strtoupper(trim((string) $b->external_reference)))->filter()->unique();
                $allSameRef = $refs->count() === 1 && $g->every(fn (Booking $b) => filled($b->external_reference));

                return ! $allSameRef;
            });

        $this->line(str_repeat('─', 48));
        if ($refDupes->isEmpty() && $journeyDupes->isEmpty()) {
            $this->info('  No duplicates found — the count is clean.');
        } else {
            $this->warn('  Possible duplicates inflating the count:');
            foreach ($refDupes as $ref => $g) {
                $this->line("   · reference {$ref} ×{$g->count()} — ".$g->first()->displayName());
            }
            foreach ($journeyDupes as $g) {
                $b = $g->first();
                $this->line('   · same journey ×'.$g->count().' — '.$b->pickup_at?->format('D d M H:i').' '.$b->displayName());
            }
            $this->line('   Run  php artisan cet:dedupe-bookings --dry-run  to review, then without --dry-run to merge.');
        }
        $this->line('');

        return self::SUCCESS;
    }
}
