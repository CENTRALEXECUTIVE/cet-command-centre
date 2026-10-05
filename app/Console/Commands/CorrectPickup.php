<?php

namespace App\Console\Commands;

use App\Models\Booking;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Correct a booking's pickup date/time on OUR records — the booking and our local
 * calendar-event mirror — and nothing else. Never contacts or writes to Google
 * Calendar (rule 1). Used to put a leg back on its real day after a cross-leg
 * mix-up (e.g. an arrival that took its return's slot), including completed jobs
 * that a re-import won't touch. The corrected time is marked as an office edit so
 * a later ETO re-ingest can't revert it.
 *
 *   php artisan cet:correct-pickup 9Y5MDRa "2026-10-04 09:15"
 *   php artisan cet:correct-pickup 9Y5MDRa "2026-10-04 09:15" --force   (no prompt)
 */
class CorrectPickup extends Command
{
    protected $signature = 'cet:correct-pickup
        {reference : the exact ETO/booking reference, e.g. 9Y5MDRa}
        {datetime : the correct pickup, UK local time, "YYYY-MM-DD HH:MM"}
        {--force : apply without the confirmation prompt}';

    protected $description = 'Set a booking\'s pickup date/time on our records (never touches Google Calendar)';

    public function handle(): int
    {
        $reference = trim((string) $this->argument('reference'));
        $booking = Booking::where('external_reference', $reference)
            ->orWhere('reference', $reference)
            ->with('calendarEvent')
            ->first();

        if (! $booking) {
            $this->error("No booking found for reference {$reference}.");

            return self::FAILURE;
        }

        // Parse in the APP timezone (Europe/London) — ETO times are UK-local, so
        // this must NOT be treated as UTC (that is the "pushed an hour late" bug).
        try {
            $new = Carbon::createFromFormat('Y-m-d H:i', trim((string) $this->argument('datetime')), config('app.timezone'));
        } catch (\Throwable) {
            $this->error('Could not read the date/time. Use the format "YYYY-MM-DD HH:MM", e.g. "2026-10-04 09:15".');

            return self::FAILURE;
        }
        if (! $new) {
            $this->error('Could not read the date/time. Use the format "YYYY-MM-DD HH:MM".');

            return self::FAILURE;
        }

        $old = $booking->pickup_at;
        $this->line('Booking   : '.($booking->external_reference ?: $booking->reference)." (id {$booking->id}, {$booking->status?->value})");
        $this->line('Customer  : '.$booking->displayName());
        $this->line('Current   : '.($old?->format('D d M Y, H:i') ?? '—'));
        $this->line('New pickup: '.$new->format('D d M Y, H:i'));

        if ($old && $old->format('Y-m-d H:i') === $new->format('Y-m-d H:i')) {
            $this->info('Already set to that time — nothing to change.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Apply this pickup time? (our records only — Google Calendar is not touched)')) {
            $this->comment('Cancelled — nothing changed.');

            return self::SUCCESS;
        }

        // Mark pickup_at as an office edit so a later ETO re-ingest leaves it alone.
        $edited = array_values(array_unique(array_merge(
            (array) ($booking->meta['edited_fields'] ?? []), ['pickup_at'],
        )));
        $meta = array_merge($booking->meta ?? [], [
            'edited_fields' => $edited,
            'manually_edited_at' => now()->toIso8601String(),
        ]);
        // Drop any stale "pickup time differs" audit flags now the time is correct.
        if (! empty($meta['audit_issues'])) {
            $meta['audit_issues'] = array_values(array_filter((array) $meta['audit_issues'],
                fn ($i) => ! str_contains((string) $i, 'pickup time') && ! str_contains((string) $i, 'Pickup time')));
        }

        $booking->forceFill(['pickup_at' => $new, 'meta' => $meta])->save();

        // Bring OUR local calendar-event mirror into line too (start +1h end), so
        // the day views agree. This updates our row ONLY — no Google push.
        if ($event = $booking->calendarEvent) {
            $event->forceFill(['start_at' => $new, 'end_at' => $new->copy()->addHour()])->save();
            $this->line('Local calendar mirror updated (not pushed to Google).');
        }

        $this->info('Done. '.($booking->external_reference ?: $booking->reference).' pickup is now '.$new->format('D d M Y, H:i').'.');

        return self::SUCCESS;
    }
}
