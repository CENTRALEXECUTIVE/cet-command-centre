<?php

namespace App\Services\Telephony;

use App\Models\Booking;
use App\Models\WatchdogEvent;
use App\Services\Watchdog\AdminAlerts;

/**
 * One place that tells the directors a call or text happened on a masked line
 * (directors, 24 Nov 2026: "notify me if there's a call or message"). Never carries
 * the content. Deduped per booking + kind for 20 seconds so retries don't spam.
 */
class MaskedActivity
{
    public static function notify(?Booking $booking, string $kind): void
    {
        $flag = $kind === 'message' ? 'masked_message' : 'masked_call';
        $recent = WatchdogEvent::where('event_type', $flag)
            ->where('booking_id', $booking?->id)
            ->where('occurred_at', '>=', now()->subSeconds(20))
            ->exists();
        if ($recent) {
            return;
        }
        if ($booking) {
            $ref = $booking->external_reference ?: $booking->reference;
            [$title, $body] = $kind === 'message'
                ? ['💬 Message on the masked line — '.$ref, 'A text on '.$ref.'. Tap to read the conversation.']
                : ['📞 Call on the masked line — '.$ref, 'A call on '.$ref.'.'];
        } else {
            [$title, $body] = $kind === 'message'
                ? ['💬 Text on a masked line', 'Someone texted a masked number but it isn’t linked to a live job right now.']
                : ['📞 Call on a masked line', 'Someone rang a masked number but it isn’t linked to a live job right now.'];
        }
        app(AdminAlerts::class)->notifyDirectors($flag, $title, $body, 'info', $booking, $booking ? '#masked-messages' : null);
    }
}
