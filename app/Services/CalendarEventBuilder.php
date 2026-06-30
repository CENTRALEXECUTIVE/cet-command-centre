<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\CalendarEvent;
use App\Models\Setting;
use Illuminate\Support\Str;

/**
 * Builds the Google Calendar event for a booking, applying CET's exact rules.
 *
 *  - Title in bold asterisks: *[emoji ]Customer AIRPORT (TAG)* where TAG is the
 *    driver's first name for Executive/rotation jobs, otherwise the mapped
 *    vehicle label (V CLASS / MINIBUS / ROLLS ROYCE / ESTATE).
 *  - Emojis: 💰 cash outstanding, 👀 card/Square/Stripe balance remaining
 *    (outbound/one-way only), 🚼 any child/booster/infant seat, none = fully paid.
 *  - Pickup address in the location field; start = pickup time; end = +1 hour;
 *    timezone Europe/London (Google applies BST/GMT automatically).
 *  - Description: the "📑 Booking Confirmation" block with bold labels.
 *  - Notifications: email 2h, push 3h/7h/1 day before; balance jobs add a 3-day push.
 */
class CalendarEventBuilder
{

    public function buildFor(Booking $booking): CalendarEvent
    {
        $moneyEmoji = $this->paymentEmoji($booking);
        $hasBalance = $moneyEmoji !== null;

        return CalendarEvent::updateOrCreate(
            ['booking_id' => $booking->id],
            [
                'calendar_id' => Setting::get('calendar_id', 'admin@centralexecutivetransfers.co.uk'),
                'title' => $this->title($booking, $moneyEmoji),
                'location' => $booking->pickup_address,
                'description' => $this->description($booking),
                'start_at' => $booking->pickup_at,
                'end_at' => $booking->pickup_at->copy()->addHour(),
                'timezone' => 'Europe/London',
                'payment_emoji' => $moneyEmoji,
                'notifications' => $this->notifications($hasBalance),
                'sync_status' => 'pending',
            ]
        );
    }

    /** *[emoji(s) ]Customer AIRPORT (TAG)* */
    private function title(Booking $booking, ?string $moneyEmoji): string
    {
        $name = $booking->meta['lead_name'] ?? $booking->customer?->name ?? 'Customer';
        $where = $booking->meta['where']
            ?? $booking->airport?->code
            ?? Str::upper(Str::words($booking->destination_address, 1, ''));
        $tag = $this->tag($booking);

        $emojis = trim(($moneyEmoji ?? '').($this->hasChildSeat($booking) ? '🚼' : ''));
        $prefix = $emojis !== '' ? "$emojis " : '';

        // Paired return legs carry a "Return" suffix (rule 4).
        $return = $booking->is_return_leg ? ' Return' : '';

        return "*{$prefix}{$name} {$where}{$return} ({$tag})*";
    }

    /**
     * The bracket tag is ALWAYS a person (rule 1) — ABDI, MAJ, COVER, or a named
     * third-party driver — NEVER a vehicle type. Vehicle appears only on the
     * "Vehicle Type" description line.
     */
    private function tag(Booking $booking): string
    {
        // An explicit tag set by the operator/import wins (ABDI/MAJ/COVER/KASH…).
        if (! empty($booking->meta['driver_tag'])) {
            return Str::upper($booking->meta['driver_tag']);
        }

        // Otherwise the assigned driver's callsign — the email local-part
        // (abdi@… → ABDI, maj@… → MAJ), else their first name.
        if ($booking->driver) {
            $callsign = Str::before((string) $booking->driver->email, '@');

            return Str::upper($callsign !== '' ? $callsign : Str::before($booking->driver->name, ' '));
        }

        // No driver yet → COVER (a person placeholder the operator overrides).
        // Never fall back to the vehicle type — that is an error per rule 1.
        return 'COVER';
    }

    /**
     * Money emoji for this leg. Only shown when a balance remains (status not
     * "paid") and only on the outbound/one-way leg; the paired return shows none.
     */
    private function paymentEmoji(Booking $booking): ?string
    {
        // A curated import carries the exact emoji the operator set in Notes.
        if (array_key_exists('money_emoji', $booking->meta ?? [])) {
            return $booking->meta['money_emoji'] ?: null;
        }

        if ($booking->is_return_leg || $booking->payment_status === 'paid') {
            return null;
        }

        return $booking->payment_method?->emoji(); // 💰 cash, 👀 card, null account
    }

    private function hasChildSeat(Booking $booking): bool
    {
        return (bool) ($booking->meta['child_seat'] ?? false);
    }

    /** Descriptive luggage from a bare count, never just a number. */
    private function luggageText(int $count): string
    {
        return $count > 0 ? $count.' Hand Luggage' : 'None';
    }

    /** The "📑 Booking Confirmation" body, bold labels on both sides. */
    private function description(Booking $booking): string
    {
        $meta = $booking->meta ?? [];
        $lines = [];
        // Header wrapped in bold asterisks; 🚼 after it so a child seat shows in
        // BOTH the title and the description. Meet & Greet is appended inside the
        // header (rule 5). NO blank line follows the header (rule 5 / rule 10).
        $childMark = $this->hasChildSeat($booking) ? ' 🚼' : '';
        $journey = $meta['journey_label'] ?? 'Transfer';
        if (! empty($meta['meet_and_greet'])) {
            $journey .= ' (Meet & Greet)';
        }
        $lines[] = '📑 *Booking Confirmation – '.$journey.'*'.$childMark;

        $add = function (string $label, ?string $value) use (&$lines): void {
            if (filled($value)) {
                $lines[] = "• *{$label}:* {$value}";
            }
        };

        // Field order per rule 5: Date, Customer, Contact, Passengers, Luggage,
        // Flight, Meet & Greet, Pickup, Drop-off, Vehicle, Payment, Ref, Notes.
        $add('Date & Time', $booking->pickup_at?->format('d/m/Y – H:i'));
        $add('Customer Name', $meta['lead_name'] ?? $booking->customer?->name);
        $add('Contact No', $meta['contact_no'] ?? $booking->customer?->phone);
        $add('Passengers', (string) $booking->passengers);
        // Luggage must be descriptive, never a bare number.
        $add('Luggage', $meta['luggage_text'] ?? $this->luggageText((int) $booking->luggage));
        if ((int) ($meta['child_seats'] ?? 0) > 0) {
            $add('Child Seats', (string) $meta['child_seats']);
        }
        if ((int) ($meta['booster_seats'] ?? 0) > 0) {
            $add('Booster Seats', (string) $meta['booster_seats']);
        }
        $add('Flight Number', $booking->flight_number);
        if (! empty($meta['meet_and_greet'])) {
            $add('Meet & Greet', 'Required');
        }
        $add('Pickup Location', $booking->pickup_address);
        foreach (array_values($meta['stops'] ?? []) as $i => $stop) {
            $add('Stop '.($i + 1), $stop);
        }
        $add('Drop-off Location', $booking->destination_address);
        $add('Vehicle Type', $booking->vehicleType?->name);
        $add('Payment', $meta['payment_text'] ?? $this->paymentLabel($booking));
        $add('Booking Reference', $booking->external_reference ?? $booking->reference);
        // Notes carry the booker (rule 3): "Booked by X". Any free-text request
        // is appended after it.
        $add('Notes', $this->notesLine($meta, $booking));

        return implode("\n", $lines);
    }

    /** "Booked by X" (rule 3), plus any special request, with no leading dash. */
    private function notesLine(array $meta, Booking $booking): ?string
    {
        $parts = [];
        $bookedBy = $meta['booked_by'] ?? null;
        $lead = $meta['lead_name'] ?? $booking->customer?->name;
        if (filled($bookedBy) && $bookedBy !== $lead) {
            $parts[] = "Booked by {$bookedBy}";
        }
        if (filled($booking->special_requests)) {
            $parts[] = $booking->special_requests;
        }

        return $parts === [] ? null : implode('. ', $parts);
    }

    /** Payment fallback with NO dash (rule 6): "Paid (Card)" / "Pending (Account)". */
    private function paymentLabel(Booking $booking): string
    {
        $method = $booking->payment_method?->label() ?? 'Card';
        $status = ucfirst($booking->payment_status ?? 'pending');

        return "{$status} ({$method})";
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function notifications(bool $hasBalance): array
    {
        $reminders = [
            ['method' => 'email', 'minutes' => 2 * 60],   // 2 hours before
            ['method' => 'popup', 'minutes' => 3 * 60],   // push 3 hours before
            ['method' => 'popup', 'minutes' => 7 * 60],   // push 7 hours before
            ['method' => 'popup', 'minutes' => 24 * 60],  // push 1 day before
        ];

        // 3-day balance push for the 👀 / 💰 outbound (or one-way) job.
        if ($hasBalance) {
            $reminders[] = ['method' => 'popup', 'minutes' => 3 * 24 * 60];
        }

        return $reminders;
    }
}
