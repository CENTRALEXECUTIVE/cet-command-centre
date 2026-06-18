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
    /** slug => calendar TAG label for non-rotation vehicles. */
    private const VEHICLE_TAG = [
        'v-class' => 'V CLASS',
        'minibus-8' => 'MINIBUS',
        'minibus-8-xl' => 'MINIBUS',
        'rolls-royce-ghost' => 'ROLLS ROYCE',
        'estate' => 'ESTATE',
    ];

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
        $name = $booking->customer?->name ?? 'Customer';
        $where = $booking->airport?->code
            ?? Str::upper(Str::words($booking->destination_address, 1, ''));
        $tag = $this->tag($booking);

        $emojis = trim(($moneyEmoji ?? '').($this->hasChildSeat($booking) ? '🚼' : ''));
        $prefix = $emojis !== '' ? "$emojis " : '';

        return "*{$prefix}{$name} {$where} ({$tag})*";
    }

    private function tag(Booking $booking): string
    {
        // Executive/rotation jobs are tagged with the assigned driver's first name.
        if ($booking->vehicleType?->affects_rotation && $booking->driver) {
            return Str::upper(Str::before($booking->driver->name, ' '));
        }

        $slug = $booking->vehicleType?->slug;

        return self::VEHICLE_TAG[$slug] ?? Str::upper($booking->vehicleType?->name ?? 'TRANSFER');
    }

    /**
     * Money emoji for this leg. Only shown when a balance remains (status not
     * "paid") and only on the outbound/one-way leg; the paired return shows none.
     */
    private function paymentEmoji(Booking $booking): ?string
    {
        if ($booking->is_return_leg || $booking->payment_status === 'paid') {
            return null;
        }

        return $booking->payment_method?->emoji(); // 💰 cash, 👀 card, null account
    }

    private function hasChildSeat(Booking $booking): bool
    {
        return (bool) ($booking->meta['child_seat'] ?? false);
    }

    /** The "📑 Booking Confirmation" body, bold labels on both sides. */
    private function description(Booking $booking): string
    {
        $meta = $booking->meta ?? [];
        $lines = [];
        $lines[] = '📑 Booking Confirmation – '.($meta['journey_label'] ?? 'Transfer');
        $lines[] = '';

        $add = function (string $label, ?string $value) use (&$lines): void {
            if (filled($value)) {
                $lines[] = "• *{$label}:* {$value}";
            }
        };

        $add('Date & Time', $booking->pickup_at?->format('D d M Y, H:i'));
        $add('Customer Name', $booking->customer?->name);
        $add('Contact No', $booking->customer?->phone ?? ($meta['contact_no'] ?? null));
        $add('Passengers', (string) $booking->passengers);
        $add('Luggage', (string) $booking->luggage);
        $add('Pickup Location', $booking->pickup_address);
        $add('Flight Number', $booking->flight_number);
        if (! empty($meta['meet_and_greet'])) {
            $add('Meet & Greet', 'Required');
        }
        $add('Drop-off Location', $booking->destination_address);
        $add('Vehicle Type', $booking->vehicleType?->name);
        $add('Payment', $meta['payment_text'] ?? $this->paymentLabel($booking));
        $add('Booking Reference', $booking->external_reference ?? $booking->reference);
        $add('Notes', $booking->special_requests);

        return implode("\n", $lines);
    }

    private function paymentLabel(Booking $booking): string
    {
        $method = $booking->payment_method?->label() ?? 'Card';
        $status = ucfirst($booking->payment_status ?? 'pending');

        return "{$method} – {$status}";
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
