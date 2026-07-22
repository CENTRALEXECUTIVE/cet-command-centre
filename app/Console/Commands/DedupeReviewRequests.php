<?php

namespace App\Console\Commands;

use App\Models\Message;
use App\Services\Messaging\BookingNotifier;
use App\Support\Phone;
use Illuminate\Console\Command;

/**
 * Tidies queued review requests so the office only ever has the RIGHT reviews to
 * send. New review requests already do the right thing; this cleans up leftovers
 * from before those fixes. Two passes:
 *
 *   1. PREMATURE — a queued review on a booking whose paired return leg hasn't
 *      completed yet. A review for the whole trip should wait for the return, so
 *      the outbound-leg review is removed (it re-queues when the return is done).
 *   2. DUPLICATE — more than one queued review for the same phone number. If a
 *      review was already SENT to that number, drop every queued one; otherwise
 *      keep the earliest queued request and drop the rest.
 *
 * Sent history is never touched.
 *
 *   php artisan cet:dedupe-reviews --dry-run   # preview, deletes nothing
 *   php artisan cet:dedupe-reviews             # actually remove them
 */
class DedupeReviewRequests extends Command
{
    protected $signature = 'cet:dedupe-reviews {--dry-run : List what would be removed without deleting anything}';

    protected $description = 'Tidy queued review requests: drop premature (return leg pending) and duplicate asks';

    public function handle(BookingNotifier $notifier): int
    {
        $dry = (bool) $this->option('dry-run');

        $all = Message::where('type', 'review_request')->with('booking')->get();

        // Pass 1: premature — the paired return leg isn't completed yet.
        $premature = $all->filter(fn (Message $m) => $m->status === 'queued'
            && $m->booking
            && $notifier->hasPendingReturnLeg($m->booking));

        foreach ($premature as $m) {
            $this->line(($dry ? '  WOULD REMOVE ' : '  removed ')
                ."premature review #{$m->id} → {$m->to_address} (return leg not completed)");
            if (! $dry) {
                $m->delete();
            }
        }

        // Pass 2: duplicates by phone number, among what's left.
        $remaining = $all->reject(fn (Message $m) => $premature->contains('id', $m->id));
        $groups = $remaining->groupBy(fn (Message $m) => Phone::wa($m->to_address) ?: '');

        $dupes = 0;
        foreach ($groups as $key => $group) {
            // No parseable number → can't be sure it's the same person; leave it.
            if ($key === '' || $group->count() < 2) {
                continue;
            }

            $alreadySent = $group->contains(fn (Message $m) => $m->status === 'sent');
            $queued = $group->where('status', 'queued')->sortBy('id')->values();

            // Keep the earliest queued one only if nothing's been sent yet.
            $toDelete = $alreadySent ? $queued : $queued->slice(1);

            foreach ($toDelete as $m) {
                $this->line(($dry ? '  WOULD REMOVE ' : '  removed ')
                    ."duplicate review #{$m->id} → {$m->to_address}");
                $dupes++;
                if (! $dry) {
                    $m->delete();
                }
            }
        }

        $prem = $premature->count();
        $this->info($dry
            ? "{$prem} premature + {$dupes} duplicate queued review request(s) found. Re-run without --dry-run to remove them."
            : "Removed {$prem} premature and {$dupes} duplicate queued review request(s).");

        return self::SUCCESS;
    }
}
