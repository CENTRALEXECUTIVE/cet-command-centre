<?php

namespace App\Support;

use App\Models\Booking;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Per-booking email history ("📧 Emails sent" on the booking page), kept in
 * booking.meta['email_log'] — no new table. Every booking-related email is
 * recorded the same way, whichever code path sent it:
 *
 *  - SUCCESS: a listener on Laravel's MessageSent event. Every booking mailable
 *    exposes a public `$booking`, which Laravel hands to the event as view data,
 *    so no send path has to remember to log.
 *  - FAILURE: MailFailure::report() (already called by every send path's catch).
 *
 * Capped to the newest CAP entries. Logging must NEVER break or block an email,
 * so every entry point swallows its own errors.
 */
class BookingEmailLog
{
    public const META_KEY = 'email_log';

    public const CAP = 50;

    /** An optional note attached to the next entry (e.g. "sent anyway — imported"). */
    private static ?string $note = null;

    /** Run $send with a note recorded against the email(s) it sends. */
    public static function withNote(?string $note, callable $send): mixed
    {
        $previous = self::$note;
        self::$note = $note;
        try {
            return $send();
        } finally {
            self::$note = $previous;
        }
    }

    /** MessageSent listener — records a successful booking email. */
    public static function onMessageSent(MessageSent $event): void
    {
        try {
            $booking = $event->data['booking'] ?? null;
            if (! $booking instanceof Booking || ! $booking->exists) {
                return;
            }

            $email = $event->message;
            $addr = fn (array $list) => implode(', ', array_map(fn ($a) => $a->getAddress(), $list));
            $to = $addr($email->getTo());
            $ops = (string) config('cet.ops_email');

            self::record($booking, [
                'type' => self::typeFor($event->data),
                'audience' => ($ops !== '' && strcasecmp($to, $ops) === 0) ? 'office' : 'customer',
                'to' => $to,
                'from' => $addr($email->getFrom()),
                'subject' => (string) $email->getSubject(),
                'ok' => true,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[email-log] could not record a sent email: '.$e->getMessage());
        }
    }

    /** Record a failed send (called from MailFailure::report). */
    public static function recordFailure(Booking $booking, string $what, \Throwable $e, ?string $to = null): void
    {
        try {
            self::record($booking, [
                'type' => ucfirst($what),
                'audience' => 'customer',
                'to' => (string) ($to ?? ''),
                'from' => $booking->senderAddress(),
                'subject' => '',
                'ok' => false,
                'error' => Str::limit($e->getMessage(), 200),
            ]);
        } catch (\Throwable $inner) {
            Log::warning('[email-log] could not record a failed email: '.$inner->getMessage());
        }
    }

    /**
     * Append one entry. Reads the CURRENT meta from the database (not the possibly
     * stale in-memory model the mailable holds) and writes quietly, so it never
     * clobbers other meta or fires the audit log for a history line.
     */
    public static function record(Booking $booking, array $entry): void
    {
        $fresh = Booking::withTrashed()->find($booking->id);
        if (! $fresh) {
            return;
        }

        $user = auth()->user();
        $entry = array_merge([
            'id' => (string) Str::ulid(),
            'at' => now()->toIso8601String(),
            'by' => $user?->name ?: 'System (automatic)',
        ], $entry);
        if (self::$note) {
            $entry['note'] = self::$note;
        }

        $meta = $fresh->meta ?? [];
        $log = array_values((array) ($meta[self::META_KEY] ?? []));
        $log[] = $entry;
        $meta[self::META_KEY] = array_slice($log, -self::CAP);

        $fresh->forceFill(['meta' => $meta])->saveQuietly();

        // Keep the caller's in-memory model in step too, so a later save of THAT
        // instance writes the history it now carries.
        $mine = $booking->meta ?? [];
        $mine[self::META_KEY] = $meta[self::META_KEY];
        $booking->meta = $mine;
    }

    /**
     * Saving guard (Booking::booted): when a booking's meta is saved from an
     * instance loaded BEFORE an email was logged, merge the stored history back
     * in so the stale save can't wipe it. Union by entry id, oldest first, capped.
     */
    public static function preserve(Booking $booking): void
    {
        try {
            $raw = DB::table($booking->getTable())->where('id', $booking->getKey())->value('meta');
            $stored = is_string($raw) ? (json_decode($raw, true)[self::META_KEY] ?? []) : [];
            if (! is_array($stored) || $stored === []) {
                return;
            }

            $meta = $booking->meta ?? [];
            $mine = (array) ($meta[self::META_KEY] ?? []);
            $byId = [];
            foreach (array_merge($stored, $mine) as $e) {
                if (is_array($e) && isset($e['id'])) {
                    $byId[$e['id']] = $e;
                }
            }
            if (count($byId) === count($mine)) {
                return; // nothing missing
            }
            $merged = array_values($byId);
            usort($merged, fn ($a, $b) => strcmp((string) ($a['at'] ?? ''), (string) ($b['at'] ?? '')) ?: strcmp($a['id'], $b['id']));
            $meta[self::META_KEY] = array_slice($merged, -self::CAP);
            $booking->meta = $meta;
        } catch (\Throwable $e) {
            Log::warning('[email-log] preserve failed: '.$e->getMessage());
        }
    }

    /** Newest-first entries for the booking page. */
    public static function entries(Booking $booking): array
    {
        return array_reverse(array_values(array_filter((array) ($booking->meta[self::META_KEY] ?? []), 'is_array')));
    }

    /** The last successful CUSTOMER confirmation entry, or null. */
    public static function lastConfirmation(Booking $booking): ?array
    {
        foreach (self::entries($booking) as $e) {
            if (($e['ok'] ?? false) && ($e['audience'] ?? '') === 'customer' && str_contains((string) ($e['type'] ?? ''), 'onfirmation')) {
                return $e;
            }
            if (($e['ok'] ?? false) && ($e['type'] ?? '') === 'Booking updated') {
                return $e;
            }
        }

        return null;
    }

    /** A readable type from the mailable class + its data. */
    public static function typeFor(array $data): string
    {
        $class = class_basename((string) ($data['__laravel_mailable'] ?? ''));

        return match ($class) {
            'BookingConfirmationMail' => match (true) {
                ! empty($data['updated']) => 'Booking updated',
                ! empty($data['paid']) => 'Paid confirmation',
                ! empty($data['payUrl']) => 'Confirmation + pay link',
                default => 'Booking confirmation',
            },
            'PaymentLinkMail' => 'Payment link',
            'BookingReceiptMail' => 'Invoice',
            'BookingCancellationMail' => 'Cancellation',
            'OfficeBookingMail' => 'Office notice ('.($data['stage'] ?? 'new').')',
            default => $class !== '' ? Str::headline(Str::beforeLast($class, 'Mail')) : 'Email',
        };
    }
}
