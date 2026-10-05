<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Services\Calendar\GoogleCalendarService;
use Illuminate\Console\Command;

/**
 * READ-ONLY diagnostic for a paired ETO booking (…a ↔ …b). Prints exactly what
 * is stored for each leg — pickup time, addresses, flight, fare, links — plus
 * each leg's calendar event and WHICH booking reference that event's text
 * actually carries. This is how we see the truth before touching anything, so a
 * cross-leg mix-up is diagnosed from the real data, never guessed. Writes
 * nothing and never contacts Google beyond reporting whether it's connected.
 *
 *   php artisan cet:inspect-pair 9Y5MDR     (base — shows both legs)
 *   php artisan cet:inspect-pair 9Y5MDRb    (either leg works)
 */
class InspectPair extends Command
{
    protected $signature = 'cet:inspect-pair {reference : an ETO reference or its base, e.g. 9Y5MDR / 9Y5MDRa}';

    protected $description = 'Read-only: show both legs of a paired ETO booking and their calendar events';

    public function handle(): int
    {
        $input = trim((string) $this->argument('reference'));
        $base = preg_replace('/[ab]$/i', '', $input);

        $candidates = array_values(array_unique(array_filter([$input, $base.'a', $base.'b', $base])));
        $bookings = Booking::query()
            ->whereIn('external_reference', $candidates)
            ->with('calendarEvent')
            ->orderBy('external_reference')
            ->get();

        if ($bookings->isEmpty()) {
            $this->warn("No bookings found for: ".implode(', ', $candidates));

            return self::SUCCESS;
        }

        $google = app(GoogleCalendarService::class);
        $this->line('Google Calendar connected: '.($google->configured() ? 'yes' : 'NO').
            ($google->configured() ? (' · active: '.($google->active() ? 'yes' : 'no')) : ''));
        $this->newLine();

        // Plain key: value lines (not a Symfony table) so the output is legible in
        // a narrow phone/cPanel terminal and nothing wraps or gets clipped.
        $row = fn (string $k, $v) => $this->line('    '.str_pad($k, 20).': '.($v === null || $v === '' ? '—' : $v));

        foreach ($bookings as $b) {
            $this->line(str_repeat('─', 56));
            $this->info("Leg {$b->external_reference}  (id {$b->id} · {$b->reference})");
            $row('status', $b->status?->value);
            $row('is_return_leg', var_export($b->is_return_leg, true));
            $row('linked_booking_id', $b->linked_booking_id);
            $row('pickup_at', $b->pickup_at?->format('D d M Y, H:i'));
            $row('pickup_address', $b->pickup_address);
            $row('destination', $b->destination_address);
            $row('flight_number', $b->flight_number);
            $row('quoted/final £', ($b->quoted_price ?? '—').' / '.($b->final_price ?? '—'));
            $row('payment', ($b->payment_method?->value ?? '?').' / '.($b->payment_status ?? '?'));
            $row('meta[where]', $b->meta['where'] ?? null);
            $row('meta[journey_label]', $b->meta['journey_label'] ?? null);
            $row('meta[lead_name]', $b->meta['lead_name'] ?? null);
            $row('edited_at', $b->meta['manually_edited_at'] ?? null);
            $row('edited_fields', implode(', ', (array) ($b->meta['edited_fields'] ?? [])) ?: null);
            $row('audit_issues', implode(' | ', (array) ($b->meta['audit_issues'] ?? [])) ?: null);

            $ev = $b->calendarEvent;
            if (! $ev) {
                $this->line('    calendar event      : (none linked)');

                continue;
            }

            // Which reference does THIS event's text actually carry? If it's the
            // SIBLING's, the booking is cross-linked to the wrong event — the exact
            // way one leg's time/address got written onto the other.
            $hay = mb_strtoupper(trim(($ev->title ?? '').' '.($ev->description ?? '')));
            $carries = [];
            foreach ($bookings as $other) {
                $ref = mb_strtoupper((string) $other->external_reference);
                if ($ref !== '' && preg_match('/(?<![A-Z0-9])'.preg_quote($ref, '/').'(?![A-Z0-9])/', $hay)) {
                    $carries[] = $other->external_reference.($other->id === $b->id ? ' (self)' : ' (SIBLING!)');
                }
            }

            $row('event google_id', $ev->google_event_id);
            $row('event sync_status', $ev->sync_status);
            $row('event title', $ev->title);
            $row('event start_at', $ev->start_at?->format('D d M Y, H:i'));

            if (collect($carries)->contains(fn ($c) => str_contains($c, 'SIBLING'))) {
                $this->line("    >>> CROSS-LINKED: this leg's event belongs to ".implode(', ', $carries));
            } else {
                $row('event carries ref', implode(', ', $carries) ?: 'NONE — cannot be verified');
            }
        }

        $this->line(str_repeat('─', 56));
        $this->comment('Read-only: nothing was changed.');

        return self::SUCCESS;
    }
}
