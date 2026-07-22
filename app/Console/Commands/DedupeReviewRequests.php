<?php

namespace App\Console\Commands;

use App\Models\Message;
use App\Support\Phone;
use Illuminate\Console\Command;

/**
 * One review ask per customer. New review requests already skip a repeat
 * customer, but bookings taken before that fix can hold duplicate QUEUED review
 * requests for the same phone number. This prunes them so the office only ever
 * has one review to send per person:
 *
 *   - if the number was already SENT a review, drop every still-queued one;
 *   - otherwise keep the earliest queued request and drop the rest.
 *
 * Sent history is never touched.
 *
 *   php artisan cet:dedupe-reviews --dry-run   # preview, deletes nothing
 *   php artisan cet:dedupe-reviews             # actually remove them
 */
class DedupeReviewRequests extends Command
{
    protected $signature = 'cet:dedupe-reviews {--dry-run : List duplicates without deleting anything}';

    protected $description = 'Remove duplicate queued review requests so each customer is asked once';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $groups = Message::where('type', 'review_request')
            ->get()
            ->groupBy(fn (Message $m) => Phone::wa($m->to_address) ?: '');

        $removed = 0;
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
                    ."queued review #{$m->id} → {$m->to_address}");
                $removed++;
                if (! $dry) {
                    $m->delete();
                }
            }
        }

        $this->info($dry
            ? "{$removed} duplicate queued review request(s) found. Re-run without --dry-run to remove them."
            : "Removed {$removed} duplicate queued review request(s).");

        return self::SUCCESS;
    }
}
