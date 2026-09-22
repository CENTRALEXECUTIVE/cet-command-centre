<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Message;
use App\Services\Messaging\BookingNotifier;
use Illuminate\Console\Command;

/**
 * Catch up the review-request queue after an outage. When the Command Centre is
 * down the hourly cet:prepare-reminders never runs, so completed jobs miss their
 * post-trip review request. This anchors on the LAST review that was queued (the
 * most recent review_request message) and queues one for every completed job
 * since — with no time-window cap, unlike prepare-reminders — so nothing between
 * the last review and now is missed.
 *
 * Idempotent and safe: ensureReviewRequest() never duplicates an existing
 * request, only queues (never sends — the office still sends on WhatsApp by
 * hand), and enforces the both-legs-done and once-per-customer rules. Use --dry
 * to list what WOULD be queued without changing anything.
 */
class CatchupReviews extends Command
{
    protected $signature = 'cet:catchup-reviews {--dry : List what would be queued without queueing anything}
        {--since= : Anchor date (Y-m-d) to catch up FROM, instead of the last review sent}';

    protected $description = 'Queue post-trip review requests for every completed job since the last review (outage catch-up)';

    public function handle(BookingNotifier $notifier): int
    {
        $dry = (bool) $this->option('dry');

        // Anchor: an explicit --since date, else the last review request queued.
        $anchor = null;
        $anchorLabel = '';
        if ($since = $this->option('since')) {
            $anchor = \Illuminate\Support\Carbon::parse($since, config('app.timezone'));
            $anchorLabel = 'since '.$anchor->format('D d M Y');
        } else {
            $last = Message::where('type', 'review_request')->orderByDesc('created_at')->first();
            $anchorBooking = $last ? Booking::find($last->booking_id) : null;
            $anchor = $anchorBooking?->pickup_at ?? $last?->created_at;
            $anchorLabel = $last
                ? 'after the last review — '.($anchorBooking?->displayName() ?? 'booking #'.$last->booking_id)
                    .' @ '.($anchor?->format('D d M Y H:i') ?? 'unknown')
                : 'no previous review found — using the last 21 days';
            if (! $anchor) {
                $anchor = now()->subDays((int) config('cet.review_backfill_days', 21));
            }
        }

        $this->info(($dry ? '[DRY RUN] ' : '').'Catching up review requests '.$anchorLabel);
        $this->line(str_repeat('-', 64));

        $completed = Booking::with('customer')
            ->where('status', BookingStatus::Complete->value)
            ->where('pickup_at', '>', $anchor)
            ->orderBy('pickup_at')
            ->get();

        $queued = 0;
        $already = 0;
        $skipped = 0;
        foreach ($completed as $booking) {
            $had = Message::where('type', 'review_request')->where('booking_id', $booking->id)->exists();

            if (! $dry) {
                $notifier->ensureReviewRequest($booking);
            }

            $has = $dry
                ? $had
                : Message::where('type', 'review_request')->where('booking_id', $booking->id)->exists();

            if ($had) {
                $state = 'already queued';
                $already++;
            } elseif ($has) {
                $state = 'QUEUED';
                $queued++;
            } else {
                // Would-be / actual skip: show why (return leg, no phone, once-per-customer).
                $reason = $booking->reviewSkipReason() ?? 'return leg or once-per-customer';
                $state = $dry ? 'would queue' : 'skipped — '.$reason;
                $dry ? $queued++ : $skipped++;
            }

            $this->line(
                $booking->pickup_at->format('D d M H:i').'  '
                .str_pad(mb_strimwidth((string) $booking->displayName(), 0, 24, ''), 26)
                .$state
            );
        }

        $this->line(str_repeat('-', 64));
        $this->info(($dry ? 'Would queue ' : 'Newly queued ').$queued
            ." · already had {$already}".($dry ? '' : " · skipped {$skipped}")
            .' · '.$completed->count().' completed job(s) checked.');
        if (! $dry && $queued > 0) {
            $this->line('Open Sales → Review requests to send them on WhatsApp.');
        }

        return self::SUCCESS;
    }
}
