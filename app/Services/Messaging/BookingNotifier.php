<?php

namespace App\Services\Messaging;

use App\Models\Booking;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Composes and dispatches the customer-facing WhatsApp messages tied to a
 * booking's lifecycle: confirmation on booking, reminders 24h and 2h before
 * pickup, and the live tracking link when the driver goes En Route.
 *
 * Reminder + driver-detail wording mirrors the exact WhatsApp text the office
 * sends by hand (WhatsApp *bold*, a "• Driver details" block, and the
 * "*Central Executive Transfers*" sign-off).
 */
class BookingNotifier
{
    /** The sign-off appended to reminder / driver-detail messages. */
    private const FOOTER = '*Central Executive Transfers*';

    public function __construct(private readonly WhatsAppService $whatsApp) {}

    /** Sent immediately when a booking is created. */
    public function sendConfirmation(Booking $booking): ?Message
    {
        $to = $booking->customer?->phone;
        if (blank($to)) {
            return null;
        }

        $body = "Hi {$this->firstName($booking)}, your Central Executive Transfers booking is confirmed."
            ."\nRef: {$booking->reference}"
            ."\nPickup: {$booking->pickup_at->format('D d M, H:i')}"
            ."\nFrom: ".Str::limit($booking->pickup_address, 80)
            ."\nTo: ".Str::limit($booking->destination_address, 80)
            ."\nVehicle: {$booking->vehicleType?->name}."
            ."\nWe'll be in touch. Reply to this message if anything changes.";

        return $this->whatsApp->send($to, $body, [
            'type' => 'confirmation',
            'booking' => $booking,
        ]);
    }

    /**
     * Queues the ~24h and 2h reminders. The 24h reminder targets 24h before
     * pickup but is shifted into the 08:00–22:00 sending window so it never
     * lands overnight (matching how the office sends them by hand) — a night
     * pickup (e.g. 05:00) is reminded at 08:00 the day before, not at 05:00.
     * The 2h nudge is only queued if it too falls inside the window.
     */
    public function scheduleReminders(Booking $booking): void
    {
        $to = $booking->customer?->phone;
        if (blank($to)) {
            return;
        }

        if (! $booking->pickup_at?->isFuture()) {
            return;
        }

        // ~24h reminder — clamped into the daytime window. If the ideal time has
        // already passed (e.g. a job booked/imported inside 24h), make it due now
        // so it lands on the "to send" list immediately.
        $at24h = $this->clampToSendWindow($booking->pickup_at->copy()->subDay());
        $this->queueReminder($booking, 'reminder_24h', $at24h->isPast() ? now() : $at24h);

        // 2h nudge — only if it naturally falls within waking hours.
        $at2h = $booking->pickup_at->copy()->subHours(2);
        if ($at2h->isFuture() && $this->withinSendWindow($at2h)) {
            $this->queueReminder($booking, 'reminder_2h', $at2h);
        }
    }

    /**
     * Make sure a future booking has its reminder(s) prepared, without ever
     * duplicating them. Used to backfill imported bookings (which don't go
     * through the booking form) so every job shows a reminder ready to send.
     */
    public function ensureReminders(Booking $booking): void
    {
        if (blank($booking->customer?->phone) || ! $booking->pickup_at?->isFuture()) {
            return;
        }
        if ($booking->messages()->whereIn('type', ['reminder_24h', 'reminder_2h'])->exists()) {
            return;
        }

        $this->scheduleReminders($booking);
    }

    private function queueReminder(Booking $booking, string $type, Carbon $when): void
    {
        $this->whatsApp->send((string) $booking->customer?->phone, $this->reminderBody($booking), [
            'type' => $type,
            'booking' => $booking,
            'scheduled_for' => $when,
        ]);
    }

    /**
     * The exact office "Booking Reminder" wording, with the driver-detail block
     * appended once a driver is allocated. Rendered at SEND time (by the delivery
     * command) so the driver/car shown is always the current one.
     */
    public function reminderBody(Booking $booking): string
    {
        $lines = [
            '*Booking Reminder*',
            '',
            'Hi '.$this->firstName($booking).',',
            '',
            'This is a reminder that your pick-up is scheduled for '
                .$this->whenPhrase($booking->pickup_at).' at *'.$booking->pickup_at->format('H:i').'*',
        ];

        if ($block = $this->driverBlock($booking)) {
            $lines[] = '';
            $lines[] = $block;
        }

        $lines[] = '';
        $lines[] = self::FOOTER;

        return implode("\n", $lines);
    }

    /** "today" / "tomorrow" relative to now, else an ordinal date like "5th July". */
    private function whenPhrase(Carbon $pickup): string
    {
        $date = $pickup->copy()->startOfDay();
        $today = now()->startOfDay();

        return match (true) {
            $date->equalTo($today) => 'today',
            $date->equalTo($today->copy()->addDay()) => 'tomorrow',
            default => $pickup->format('jS F'),
        };
    }

    /**
     * The "• Driver details" block, or null if no driver is known. Prefers the
     * details the operator entered for this job (meta['driver_details'] — covers
     * third-party drivers not in the system), then the assigned driver + vehicle.
     */
    private function driverBlock(Booking $booking): ?string
    {
        // Operator-entered details for this specific job (any driver, incl. cover).
        $manual = $booking->meta['driver_details'] ?? null;
        if (is_array($manual) && filled($manual['name'] ?? null)) {
            return $this->formatDriverBlock(
                $manual['name'], $manual['phone'] ?? null, $manual['reg'] ?? null, $manual['car'] ?? null
            );
        }

        $driver = $booking->driver;
        if (! $driver) {
            return null;
        }

        $vehicle = $booking->vehicle ?? $driver->driverProfile?->defaultVehicle;
        $car = trim(implode(' ', array_filter([$vehicle?->colour, $vehicle?->make, $vehicle?->model])));

        return $this->formatDriverBlock(
            $this->driverDisplayName($driver), $driver->phone, $vehicle?->registration, $car
        );
    }

    /** Format the WhatsApp "• Driver details" block from raw values. */
    private function formatDriverBlock(?string $name, ?string $phone, ?string $reg, ?string $car): string
    {
        $lines = ['*Driver details*'];
        $lines[] = '• Driver Name: '.$name;
        if (filled($phone)) {
            $lines[] = '• Driver Contact Number: '.$phone;
        }
        if (filled($reg)) {
            $lines[] = '• Vehicle Reg: '.Str::upper($reg);
        }
        if (filled($car)) {
            $lines[] = '• Vehicle Make & Model: '.Str::upper($car);
        }

        return implode("\n", $lines);
    }

    /**
     * The name shown to customers: the operator-set callsign if present, else the
     * login local-part (abdi@… → "Abdi"), else the driver's first name.
     */
    private function driverDisplayName(User $driver): string
    {
        if (filled($driver->driverProfile?->callsign)) {
            return $driver->driverProfile->callsign;
        }

        $local = Str::before((string) $driver->email, '@');

        if ($local !== '' && ctype_alpha($local)) {
            return Str::ucfirst(Str::lower($local));
        }

        return Str::before($driver->name, ' ');
    }

    /** The daytime sending window [start, end] on the given day. */
    private function sendWindow(\Illuminate\Support\Carbon $day): array
    {
        [$sh, $sm] = array_pad(explode(':', (string) config('cet.send_window.start', '08:00')), 2, 0);
        [$eh, $em] = array_pad(explode(':', (string) config('cet.send_window.end', '23:00')), 2, 0);

        return [
            $day->copy()->setTime((int) $sh, (int) $sm),
            $day->copy()->setTime((int) $eh, (int) $em),
        ];
    }

    private function withinSendWindow(\Illuminate\Support\Carbon $when): bool
    {
        [$start, $end] = $this->sendWindow($when);

        return $when->betweenIncluded($start, $end);
    }

    /**
     * Move a send time into the daytime window: before the start → the start of
     * that same day; after the end → the end of that same day.
     */
    private function clampToSendWindow(\Illuminate\Support\Carbon $when): \Illuminate\Support\Carbon
    {
        [$start, $end] = $this->sendWindow($when);

        if ($when->lt($start)) {
            return $start;
        }
        if ($when->gt($end)) {
            return $end;
        }

        return $when;
    }

    /**
     * Queues the review request for ~30 minutes after job completion, clamped
     * into the 08:00–22:00 window so it never lands overnight (a job completed
     * late at night becomes a review to send at 08:00). Left QUEUED for the
     * office to send by hand on WhatsApp — never auto-sent to the customer — so
     * it appears as a task on the dashboard and the booking, exactly like a
     * pickup reminder. No duplicates.
     */
    public function scheduleReviewRequest(Booking $booking): ?Message
    {
        $to = $booking->customer?->phone;
        if (blank($to)) {
            return null;
        }

        // Return journeys get ONE review for the whole trip, only after BOTH
        // legs are completed — never a review mid-trip when just the outbound
        // is done. One-way jobs are unaffected.
        $sibling = $this->pairedLeg($booking);
        if ($sibling) {
            if ($sibling->status->value !== 'complete') {
                return null; // other leg still to run — ask after the full trip
            }
            if ($sibling->messages()->where('type', 'review_request')->exists()) {
                return null; // the pair already has its review request
            }
        }

        if ($booking->messages()->where('type', 'review_request')->exists()) {
            return null;
        }

        $delay = (int) config('cet.review_delay_minutes', 30);
        $when = $this->clampToSendWindow(now()->addMinutes($delay));

        return $this->whatsApp->send($to, $this->reviewBody($booking), [
            'type' => 'review_request',
            'booking' => $booking,
            'scheduled_for' => $when->isPast() ? now() : $when,
        ]);
    }

    /**
     * The other leg of a return pair (linked either direction), or null for a
     * one-way job.
     */
    private function pairedLeg(Booking $booking): ?Booking
    {
        if ($booking->linked_booking_id) {
            return Booking::find($booking->linked_booking_id);
        }

        return Booking::where('linked_booking_id', $booking->id)->first();
    }

    /**
     * The exact office "leave us a review" wording, greeting the LEAD PASSENGER.
     * Rendered fresh at send time so the name/link are always current.
     */
    public function reviewBody(Booking $booking): string
    {
        return implode("\n", [
            'Hi '.$this->firstName($booking).', we hope you had a smooth journey with '.self::FOOTER.'!',
            "We'd love to hear your feedback.",
            'If you could take a moment to leave us a quick review, it really helps us improve our service and means a lot to us 🙏',
            '👉 Google: '.config('cet.review_url'),
            'Thank you for choosing us, and we look forward to assisting you again soon.',
            self::FOOTER,
            '🌐 '.config('cet.website'),
        ]);
    }

    /**
     * "Here's your driver" — sent when a driver is allocated, giving the customer
     * the driver's name and the car (make, colour, registration) so they know
     * exactly who and what to look for. Skipped if no driver/number is set.
     */
    public function sendDriverDetails(Booking $booking): ?Message
    {
        $to = $booking->customer?->phone;
        $block = $this->driverBlock($booking);
        if (blank($to) || ! $block) {
            return null;
        }

        $body = $block."\n\n".self::FOOTER;

        return $this->whatsApp->send($to, $body, [
            'type' => 'driver_details',
            'booking' => $booking,
        ]);
    }

    /**
     * Sent when the driver marks Passenger On Board — reassures the booker
     * (often not the passenger, e.g. a company booking a client's transfer)
     * that the journey is underway.
     */
    public function sendPassengerOnBoard(Booking $booking): ?Message
    {
        $to = $booking->customer?->phone;
        if (blank($to)) {
            return null;
        }

        $body = 'Passenger on board — your Central Executive Transfers journey is underway. '
            ."Ref: {$booking->reference}";

        return $this->whatsApp->send($to, $body, [
            'type' => 'custom',
            'booking' => $booking,
        ]);
    }

    /** Sent when the driver marks Arrived at the pickup. */
    public function sendArrived(Booking $booking): ?Message
    {
        $to = $booking->customer?->phone;
        if (blank($to)) {
            return null;
        }

        $driver = $booking->driver?->name ? " ({$booking->driver->name})" : '';
        $body = "Your Central Executive Transfers driver{$driver} has arrived at the pickup point. "
            ."Ref: {$booking->reference}";

        return $this->whatsApp->send($to, $body, ['type' => 'custom', 'booking' => $booking]);
    }

    /** Sent when the driver goes En Route, including the live tracking link. */
    public function sendTrackingLink(Booking $booking, string $url): ?Message
    {
        $to = $booking->customer?->phone;
        if (blank($to)) {
            return null;
        }

        $body = "Your CET driver is on the way. Track your car live: {$url}"
            ."\nRef: {$booking->reference}";

        return $this->whatsApp->send($to, $body, [
            'type' => 'tracking_link',
            'booking' => $booking,
        ]);
    }

    /**
     * The greeting name — the LEAD PASSENGER (who's actually travelling), not the
     * booker. Uses meta['lead_name'], then the name on the calendar event title
     * (what the operator already sees), then the customer.
     */
    private function firstName(Booking $booking): string
    {
        // Trust the calendar title first — it's the operator's source of truth
        // and always leads with the passenger — then meta, then the customer.
        $lead = $this->nameFromCalendarTitle($booking)
            ?: ($booking->meta['lead_name'] ?? null)
            ?: $booking->customer?->name;

        return Str::before(trim((string) ($lead ?: 'there')), ' ') ?: 'there';
    }

    /**
     * The passenger name from the calendar event title, e.g. "*Jo Brown EMA
     * (COVER)*" → "Jo Brown". Strips the bold markers/emojis, the airport code
     * and the driver tag.
     */
    private function nameFromCalendarTitle(Booking $booking): ?string
    {
        $title = $booking->calendarEvent?->title;
        if (blank($title)) {
            return null;
        }

        $name = trim(preg_replace('/[*👀💰🚼🚸✈️🛬]/u', '', Str::before($title, ' (')));
        $name = trim((string) preg_replace(
            '/\s+(MAN|LHR|LGW|STN|EMA|LBA|HUY|LPL|BHX|LTN|BRS|EDI|GLA|NCL|LCY|DSA|Free Roam|Return).*$/iu',
            '',
            $name
        ));

        return $name !== '' ? $name : null;
    }
}
