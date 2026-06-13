<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\CalendarEvent;
use App\Models\Setting;
use Illuminate\Support\Str;

/**
 * Builds the Google Calendar event row for a booking, applying CET's exact
 * formatting rules. The actual Google API push is performed by the calendar
 * sync job (Phase 1 integration); this guarantees every event is formatted
 * correctly before it is sent.
 *
 * Rules implemented:
 *  - Title wrapped in bold asterisks, e.g. *👀 Geoff Bowen LHR (ABDI)*
 *    or *James Watson MAN (MINIBUS)*.
 *  - Tag is the driver's first name (uppercase) for rotation/Executive jobs,
 *    otherwise the vehicle type name (uppercase).
 *  - Pickup address goes in the location field.
 *  - Start = pickup time exactly; end = pickup + 1 hour.
 *  - Payment emoji: 💰 cash outstanding, 👀 card balance remaining.
 *    Paired booking → emoji + 3-day balance push on the OUTBOUND only;
 *    cash return → 💰 on outbound only, return shows "Paid".
 *  - Notifications: email 2h before, push 3h/7h/1 day before (+ 3-day push for
 *    balance-remaining jobs).
 */
class CalendarEventBuilder
{
    public function buildFor(Booking $booking): CalendarEvent
    {
        $emoji = $this->paymentEmoji($booking);
        $title = $this->title($booking, $emoji);

        return CalendarEvent::updateOrCreate(
            ['booking_id' => $booking->id],
            [
                'calendar_id' => Setting::get('calendar_id', 'admin@centralexecutivetransfers.co.uk'),
                'title' => $title,
                'location' => $booking->pickup_address,
                'start_at' => $booking->pickup_at,
                'end_at' => $booking->pickup_at->copy()->addHour(),
                'timezone' => 'Europe/London',
                'payment_emoji' => $emoji,
                'notifications' => $this->notifications($booking, $emoji !== null),
                'sync_status' => 'pending',
            ]
        );
    }

    /** *[emoji ]Customer DEST (TAG)* */
    private function title(Booking $booking, ?string $emoji): string
    {
        $name = $booking->customer?->name ?? 'Customer';
        $where = $booking->airport?->code
            ?? Str::upper(Str::words($booking->destination_address, 1, ''));
        $tag = $this->tag($booking);

        $prefix = $emoji ? "$emoji " : '';

        return "*{$prefix}{$name} {$where} ({$tag})*";
    }

    private function tag(Booking $booking): string
    {
        // Executive/rotation jobs are tagged with the driver's first name.
        if ($booking->vehicleType?->affects_rotation && $booking->driver) {
            return Str::upper(Str::before($booking->driver->name, ' '));
        }

        return Str::upper($booking->vehicleType?->name ?? 'TRANSFER');
    }

    /**
     * Determine the payment emoji for this leg. Only the leg that carries the
     * outstanding balance shows an emoji; the paired return shows none ("Paid").
     */
    private function paymentEmoji(Booking $booking): ?string
    {
        // Return legs never carry the emoji — the outbound holds the balance.
        if ($booking->is_return_leg) {
            return null;
        }

        return match ($booking->payment_method) {
            PaymentMethod::Cash => '💰',
            PaymentMethod::Card => '👀',
            default => null, // Account work is invoiced — no emoji.
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function notifications(Booking $booking, bool $hasBalance): array
    {
        $reminders = [
            ['method' => 'email', 'minutes' => 2 * 60],   // 2 hours before
            ['method' => 'popup', 'minutes' => 3 * 60],   // push 3 hours before
            ['method' => 'popup', 'minutes' => 7 * 60],   // push 7 hours before
            ['method' => 'popup', 'minutes' => 24 * 60],  // push 1 day before
        ];

        // 3-day balance push for the 👀 / 💰 outbound (or one-way) job.
        if ($hasBalance && ! $booking->is_return_leg) {
            $reminders[] = ['method' => 'popup', 'minutes' => 3 * 24 * 60];
        }

        return $reminders;
    }
}
