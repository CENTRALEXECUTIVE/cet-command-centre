<?php

namespace App\Console\Commands;

use App\Models\Booking;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Correct a booking's pickup date/time — and, optionally, its pickup / drop-off
 * addresses — on OUR records (the booking and our local calendar-event mirror),
 * and nothing else. Never contacts or writes to Google Calendar (rule 1).
 *
 * Used to put a leg back to what its ETO email says after a cross-leg mix-up
 * (e.g. a return showing its arrival's date and reversed addresses), including
 * completed jobs a re-import won't touch. Every corrected field is marked as an
 * office edit, so it also WINS over a stale/cross-linked calendar event in the
 * display and can't be reverted by a later ETO re-ingest.
 *
 *   php artisan cet:correct-pickup 9Y5MDRa "2026-10-04 09:15"
 *   php artisan cet:correct-pickup 9Y5MDRb "2026-10-08 09:00" \
 *       --pickup="2 Worrygoose Lane, Whiston, Rotherham, UK, S60 4AD" \
 *       --dropoff="Terminal 2, Manchester, UK"
 */
class CorrectPickup extends Command
{
    protected $signature = 'cet:correct-pickup
        {reference : the exact ETO/booking reference, e.g. 9Y5MDRa}
        {datetime : the correct pickup, UK local time, "YYYY-MM-DD HH:MM"}
        {--pickup= : optional corrected pickup address}
        {--dropoff= : optional corrected drop-off address}
        {--force : apply without the confirmation prompt}';

    protected $description = 'Set a booking\'s pickup time (and optionally addresses) on our records — never touches Google Calendar';

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
        $new = null;
        try {
            $new = Carbon::createFromFormat('Y-m-d H:i', trim((string) $this->argument('datetime')), config('app.timezone'));
        } catch (\Throwable) {
            // handled below
        }
        if (! $new) {
            $this->error('Could not read the date/time. Use the format "YYYY-MM-DD HH:MM", e.g. "2026-10-04 09:15".');

            return self::FAILURE;
        }

        $newPickup = $this->option('pickup') !== null ? trim((string) $this->option('pickup')) : null;
        $newDropoff = $this->option('dropoff') !== null ? trim((string) $this->option('dropoff')) : null;

        $this->line('Booking     : '.($booking->external_reference ?: $booking->reference)." (id {$booking->id}, {$booking->status?->value})");
        $this->line('Customer    : '.$booking->displayName());
        $this->line('Pickup time : '.($booking->pickup_at?->format('D d M Y, H:i') ?? '—').'  ->  '.$new->format('D d M Y, H:i'));
        if ($newPickup !== null) {
            $this->line('Pickup addr : '.$booking->pickup_address.'  ->  '.$newPickup);
        }
        if ($newDropoff !== null) {
            $this->line('Drop-off    : '.$booking->destination_address.'  ->  '.$newDropoff);
        }

        if (! $this->option('force') && ! $this->confirm('Apply these corrections? (our records only — Google Calendar is not touched)')) {
            $this->comment('Cancelled — nothing changed.');

            return self::SUCCESS;
        }

        // Mark every corrected field as an office edit, so it (a) WINS over a
        // stale/cross-linked calendar event in the display, and (b) can't be
        // reverted by a later ETO re-ingest.
        $editedNow = ['pickup_at'];
        $fields = ['pickup_at' => $new];
        if ($newPickup !== null) {
            $fields['pickup_address'] = $newPickup;
            $editedNow[] = 'pickup_address';
        }
        if ($newDropoff !== null) {
            $fields['destination_address'] = $newDropoff;
            $editedNow[] = 'destination_address';
        }

        $edited = array_values(array_unique(array_merge((array) ($booking->meta['edited_fields'] ?? []), $editedNow)));
        $meta = array_merge($booking->meta ?? [], [
            'edited_fields' => $edited,
            'manually_edited_at' => now()->toIso8601String(),
        ]);
        // Drop any stale "pickup time / location differs" audit flags.
        if (! empty($meta['audit_issues'])) {
            $meta['audit_issues'] = array_values(array_filter((array) $meta['audit_issues'],
                fn ($i) => stripos((string) $i, 'pickup time') === false
                    && stripos((string) $i, 'pickup location') === false
                    && stripos((string) $i, 'drop') === false));
        }
        $fields['meta'] = $meta;

        $booking->forceFill($fields)->save();

        // Keep driver nav accurate: re-geocode any changed address (best-effort,
        // never fatal — a null just falls back to an address search).
        if ($newPickup !== null || $newDropoff !== null) {
            try {
                $geocoder = app(\App\Services\GeocodingService::class);
                $geo = $booking->meta['geo'] ?? [];
                if ($newPickup !== null) {
                    $geo['pickup'] = $geocoder->coords($newPickup);
                }
                if ($newDropoff !== null) {
                    $geo['dropoff'] = $geocoder->coords($newDropoff);
                }
                $booking->forceFill(['meta' => array_merge($booking->meta ?? [], ['geo' => $geo])])->save();
            } catch (\Throwable) {
                // leave coordinates as they were
            }
        }

        // Bring OUR local calendar mirror into line (start +1h end). This updates
        // our row ONLY and does NOT mark it for a Google push.
        if ($event = $booking->calendarEvent) {
            $event->forceFill(['start_at' => $new, 'end_at' => $new->copy()->addHour()])->save();
            $this->line('Local calendar mirror time updated (not pushed to Google).');
        }

        $this->info('Done. '.($booking->external_reference ?: $booking->reference).' corrected on our records.');

        return self::SUCCESS;
    }
}
