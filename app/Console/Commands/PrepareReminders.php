<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\Messaging\BookingNotifier;
use Illuminate\Console\Command;

/**
 * Makes sure every upcoming booking has its pickup reminder prepared, and every
 * recently-completed job has its review request prepared — including ones that
 * bypassed the booking form / live status flow (ETO imports). On the "to send"
 * lists. Idempotent: never duplicates an existing reminder or review request.
 */
class PrepareReminders extends Command
{
    protected $signature = 'cet:prepare-reminders';

    protected $description = 'Prepare pickup reminders and post-trip review requests';

    public function handle(BookingNotifier $notifier): int
    {
        $upcoming = Booking::with('customer')
            ->where('pickup_at', '>=', now())
            ->whereNotIn('status', [
                BookingStatus::Cancelled->value,
                BookingStatus::NoShow->value,
                BookingStatus::Complete->value,
            ])
            ->get();

        foreach ($upcoming as $booking) {
            $notifier->ensureReminders($booking);
        }

        // Review requests for recently-completed jobs without one (window from
        // config so previous jobs are covered too).
        $completed = Booking::with('customer')
            ->where('status', BookingStatus::Complete->value)
            ->whereBetween('pickup_at', [now()->subDays((int) config('cet.review_backfill_days', 21)), now()])
            ->get();

        foreach ($completed as $booking) {
            $notifier->ensureReviewRequest($booking);
        }

        $this->info("Checked {$upcoming->count()} upcoming reminder(s) and {$completed->count()} completed job(s) for reviews.");

        return self::SUCCESS;
    }
}
