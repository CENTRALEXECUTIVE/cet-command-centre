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

    /**
     * Build the CET title / location / description for a booking WITHOUT saving
     * anything — used to preview a pasted booking before the operator confirms.
     *
     * @return array{title: string, location: ?string, description: string}
     */
    public function preview(Booking $booking): array
    {
        $moneyEmoji = $this->paymentEmoji($booking);

        return [
            'title' => $this->title($booking, $moneyEmoji),
            'location' => $booking->pickup_address,
            'description' => $this->description($booking),
        ];
    }

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
        // Prefer a real airport: the set airport, else one detected from the
        // addresses (so "Terminal 2, Manchester" reads as MAN, not FREE ROAM),
        // then any stored/custom where, then the destination word.
        $where = $booking->airport?->code
            ?? \App\Support\AirportMatcher::codeFor($booking->pickup_address, $booking->destination_address)
            ?? $booking->meta['where']
            ?? Str::upper(Str::words($booking->destination_address, 1, ''));
        $tag = $this->tag($booking);

        $emojis = trim(($moneyEmoji ?? '').($this->hasChildSeat($booking) ? '🚼' : ''));
        $prefix = $emojis !== '' ? "$emojis " : '';

        // Paired return legs carry a "Return" suffix (rule 4).
        $return = $booking->is_return_leg ? ' Return' : '';

        return "*{$prefix}{$name} {$where}{$return} ({$tag})*";
    }

    /**
     * The bracket tag names WHO is on the job once allocated — the driver's
     * callsign (ABDI/MAJ) or name. UNTIL a driver is allocated it shows the
     * VEHICLE TYPE needed (e.g. V CLASS, EXECUTIVE), so an unallocated job tells
     * the office what car to put on it instead of a bare "COVER". The moment a
     * driver is allocated, the tag becomes their name.
     */
    private function tag(Booking $booking): string
    {
        // An explicit driver tag set by the operator/import wins (ABDI/MAJ/KASH…),
        // but the old "COVER" placeholder is superseded by the vehicle type below.
        $explicit = $booking->meta['driver_tag'] ?? null;
        if (filled($explicit) && Str::upper(trim($explicit)) !== 'COVER') {
            return Str::upper($explicit);
        }

        // Allocated → the driver's callsign: the operator-set callsign, else the
        // email local-part (abdi@… → ABDI), else their first name.
        if ($booking->driver) {
            if (filled($booking->driver->driverProfile?->callsign)) {
                return Str::upper($booking->driver->driverProfile->callsign);
            }

            $callsign = Str::before((string) $booking->driver->email, '@');

            return Str::upper($callsign !== '' ? $callsign : Str::before($booking->driver->name, ' '));
        }

        // Not allocated yet → show the vehicle type so dispatch knows what to send;
        // becomes the driver's name on allocation. COVER only if there's no vehicle.
        return Str::upper($booking->vehicleType?->name ?: 'COVER');
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

    /**
     * The descriptive luggage line for the calendar — the suitcase + hand-luggage
     * split when we have it (from ETO or the booking form), so the calendar
     * carries the real breakdown that the rest of the app mirrors back. Falls
     * back to the pre-supplied text, then a bare count, then "None".
     */
    private function luggageText(Booking $booking): string
    {
        $meta = $booking->meta ?? [];
        if (filled($meta['luggage_text'] ?? null)) {
            return $meta['luggage_text'];
        }

        $suitcases = (int) ($meta['suitcases'] ?? 0);
        $hand = (int) ($meta['hand_luggage'] ?? 0);
        $parts = [];
        if ($suitcases > 0) {
            $parts[] = $suitcases.' Suitcase'.($suitcases > 1 ? 's' : '');
        }
        if ($hand > 0) {
            $parts[] = $hand.' Hand Luggage';
        }
        if ($parts) {
            return implode(' + ', $parts);
        }

        $total = (int) $booking->luggage;

        return $total > 0 ? $total.' Hand Luggage' : 'None';
    }

    /**
     * "Departure / Arrival / Transfer", with "(Meet & Greet)" appended exactly ONCE
     * — never twice, even when a stored journey_label already includes it (which was
     * doubling the suffix on imported bookings).
     */
    private function journeyLabel(Booking $booking): string
    {
        $label = $booking->meta['journey_label'] ?? 'Transfer';
        if (! empty($booking->meta['meet_and_greet']) && stripos($label, 'meet') === false) {
            $label .= ' (Meet & Greet)';
        }

        return $label;
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
        $lines[] = '📑 *Booking Confirmation – '.$this->journeyLabel($booking).'*'.$childMark;

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
        $add('Luggage', $this->luggageText($booking));
        if ((int) ($meta['child_seats'] ?? 0) > 0) {
            $add('Child Seats', (string) $meta['child_seats']);
        }
        if ((int) ($meta['infant_seats'] ?? 0) > 0) {
            $add('Infant Seats', (string) $meta['infant_seats']);
        }
        if ((int) ($meta['booster_seats'] ?? 0) > 0) {
            $add('Booster Seats', (string) $meta['booster_seats']);
        }
        $add('Flight Number', $booking->flight_number);
        if (! empty($meta['meet_and_greet'])) {
            $add('Meet & Greet', 'Required');
        }
        // Use the authoritative (display) values and the full stop list so an
        // edit made in the Command Centre — new addresses, an added stop — is
        // reflected here, not just the raw import columns.
        $add('Pickup Location', $booking->displayPickupAddress() ?: $booking->pickup_address);
        foreach ($booking->viaStops() as $i => $stop) {
            $add('Stop '.($i + 1), $stop);
        }
        $add('Drop-off Location', $booking->displayDropoffAddress() ?: $booking->destination_address);
        $add('Vehicle Type', $booking->vehicleType?->name);
        $add('Payment', $meta['payment_text'] ?? $this->paymentLabel($booking));
        $add('Booking Reference', $booking->external_reference ?? $booking->reference);
        // Notes carry the booker (rule 3): "Booked by X". Any free-text request
        // is appended after it.
        $add('Notes', $this->notesLine($meta, $booking));

        return implode("\n", $lines);
    }

    /**
     * The office → driver brief. The SAME block the calendar carries, but with
     * the three things a driver must not see stripped out: the price (shows just
     * "Paid" / "Cash on the day", no amount), the booking reference (removed),
     * and the customer's real number (the masked CET line is shown instead).
     */
    public function driverBrief(Booking $booking): string
    {
        $meta = $booking->meta ?? [];
        $lines = [];
        $childMark = $this->hasChildSeat($booking) ? ' 🚼' : '';
        $lines[] = '📑 *Booking Confirmation – '.$this->journeyLabel($booking).'*'.$childMark;

        $add = function (string $label, ?string $value) use (&$lines): void {
            if (filled($value)) {
                $lines[] = "• *{$label}:* {$value}";
            }
        };

        // Use the DISPLAY values (calendar-authoritative) so the brief matches
        // exactly what the office sees and what's actually happening.
        $add('Date & Time', $booking->pickup_at?->format('d/m/Y – H:i'));
        $add('Customer Name', $meta['lead_name'] ?? $booking->displayCustomerName());
        // Masked CET line — never the customer's real number (even owner-driver).
        $add('Contact No', $booking->driverBriefContact());
        $add('Passengers', (string) $booking->passengerCount());
        $add('Luggage', $this->luggageText($booking));
        if ((int) ($meta['child_seats'] ?? 0) > 0) {
            $add('Child Seats', (string) $meta['child_seats']);
        }
        if ((int) ($meta['infant_seats'] ?? 0) > 0) {
            $add('Infant Seats', (string) $meta['infant_seats']);
        }
        if ((int) ($meta['booster_seats'] ?? 0) > 0) {
            $add('Booster Seats', (string) $meta['booster_seats']);
        }
        $add('Flight Number', $booking->displayFlightNumber() ?: $booking->flight_number);
        if (! empty($meta['meet_and_greet'])) {
            $add('Meet & Greet', 'Required');
        }
        $add('Pickup Location', $booking->displayPickupAddress());
        // All via stops, wherever they came from (booking form, intake, ETO) —
        // not just meta['stops'] — so a form-added stop still reaches the driver.
        foreach ($booking->viaStops() as $i => $stop) {
            $add('Stop '.($i + 1), $stop);
        }
        $add('Drop-off Location', $booking->displayDropoffAddress());
        $add('Vehicle Type', $booking->displayVehicleType() ?: $booking->vehicleType?->name);
        if ($booking->isRibbonJob()) {
            $add('Ribbon', 'Required 🎀');
        }
        if ($booking->hasWaitingTime()) {
            $add('Waiting time', $booking->waitingTimeLabel() ?: 'Yes — see notes');
        }
        // Paid vs cash only — no amount for the driver.
        $add('Payment', $this->driverPaymentLabel($booking));
        // Deliberately NO Booking Reference for drivers.
        $add('Notes', $this->notesLine($meta, $booking));

        return implode("\n", $lines);
    }

    /**
     * For the driver: the CASH TO COLLECT with its amount (e.g. "£110 to collect
     * (cash)"), stripped of any deposit already paid — that's what the driver
     * needs. A fully-paid job just says "Paid" (no amount).
     */
    private function driverPaymentLabel(Booking $booking): string
    {
        $collect = $booking->driverCollectLine();

        // A real amount to collect → show it verbatim.
        if ($collect !== null && ! str_contains($collect, 'collect nothing')) {
            return $collect;
        }

        $paidText = strtolower((string) ($booking->meta['payment_text'] ?? ''));
        if ($collect !== null || $booking->payment_status === 'paid' || str_contains($paidText, 'paid')) {
            return 'Paid';
        }

        return 'Cash on the day';
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
