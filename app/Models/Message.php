<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $fillable = [
        'booking_id', 'customer_id', 'channel', 'direction', 'type', 'to_address',
        'body', 'status', 'provider_message_id', 'scheduled_for', 'sent_at', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'sent_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Is this one of the scheduled pickup reminders? */
    public function isReminder(): bool
    {
        return in_array($this->type, ['reminder_24h', 'reminder_2h'], true);
    }

    /** Is this the post-journey "leave us a review" request? */
    public function isReviewRequest(): bool
    {
        return $this->type === 'review_request';
    }

    /** Is this the post-journey "leave your driver a tip" link? */
    public function isTipRequest(): bool
    {
        return $this->type === 'tip_request';
    }

    /**
     * A customer message the office sends BY HAND on a schedule — a pickup
     * reminder, a post-journey review request, or the post-journey tip link.
     * These are never auto-delivered; they wait as a task until their (windowed)
     * send time and are then sent manually from WhatsApp Business.
     */
    public function isScheduledPrompt(): bool
    {
        return $this->isReminder() || $this->isReviewRequest() || $this->isTipRequest();
    }

    /**
     * Should the manual "Send on WhatsApp" prompt be offered yet? A scheduled
     * reminder/review stays hidden until its (windowed) send time arrives, so the
     * office is never nudged to message a customer early or overnight. Anything
     * else (confirmation, driver details, one-off messages) is always sendable.
     */
    public function isReadyToSend(): bool
    {
        return ! ($this->isScheduledPrompt() && $this->scheduled_for && $this->scheduled_for->isFuture());
    }

    /**
     * The message text as it should be shown/sent NOW. Any internal link
     * (tip / track / driver) that was frozen into the body with an old host —
     * e.g. an earlier "staging." address — is rewritten to the current app host,
     * so a queued or historical message never shows a stale/ugly domain. The
     * marketing site link (www.…, no /tip|track|job path) and external links
     * (Google review) are left untouched.
     */
    public function renderedBody(): string
    {
        // Driver-details messages are re-rendered live from the current driver /
        // vehicle (and current wording) so the booking page and any re-send always
        // reflect what's actually on the job now — not the text frozen when the
        // driver was first allocated. Falls back to the stored body if we can't.
        if ($this->type === 'driver_details' && $this->booking) {
            $live = app(\App\Services\Messaging\BookingNotifier::class)->driverDetailsBody($this->booking);
            $body = $live ?: (string) $this->body;
        } else {
            $body = (string) $this->body;
        }

        $host = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (! $host) {
            return $body;
        }

        return preg_replace(
            '#https://[a-z0-9.-]*centralexecutivetransfers\.co\.uk(/(?:tip|track|job)/)#i',
            'https://'.$host.'$1',
            $body
        );
    }

    /** The recipient number in international format (44…) for wa.me links. */
    public function intlPhone(): ?string
    {
        return \App\Support\Phone::wa($this->recipientNumber());
    }

    /**
     * The number this message should actually go to. For a booking message that's
     * the booking's CURRENT customer contact (the calendar number) — so a message
     * queued before the number was corrected still reaches the right person, not
     * the stale recipient frozen in `to_address`. Falls back to `to_address` for
     * messages with no booking.
     */
    public function recipientNumber(): ?string
    {
        return $this->booking?->customerContactNumber() ?: $this->to_address;
    }

    /**
     * A click-to-send WhatsApp link: opens WhatsApp (app or web) with the
     * recipient AND this message's text pre-filled, ready for the operator to
     * send by hand — no API, no cost. Null if there's no usable phone number.
     */
    public function whatsAppLink(): ?string
    {
        $phone = $this->intlPhone();
        if (! $phone) {
            return null;
        }

        return 'https://wa.me/'.$phone.'?text='.rawurlencode($this->renderedBody());
    }

    /**
     * A click-to-email link (mailto:) for customers who don't use WhatsApp: opens
     * the operator's own mail app with the customer's address, a subject and this
     * message's text pre-filled, ready to review and send by hand — same manual,
     * free flow as the WhatsApp link. Null when there's no email on file. The
     * WhatsApp *bold* markers are stripped so it reads cleanly as an email.
     */
    public function emailLink(): ?string
    {
        $email = $this->booking?->customer?->email ?: $this->customer?->email;
        if (blank($email)) {
            return null;
        }

        $body = preg_replace('/\*(.+?)\*/s', '$1', $this->renderedBody());

        return 'mailto:'.$email
            .'?subject='.rawurlencode($this->emailSubject())
            .'&body='.rawurlencode($body);
    }

    /** A readable email subject for this message's type. */
    public function emailSubject(): string
    {
        // Use the customer-facing ETO reference (external_reference) — the one the
        // customer already knows from ETO — not our internal CET reference.
        $ref = $this->booking?->external_reference ?: $this->booking?->reference;

        return match (true) {
            $this->isReminder() => 'Your upcoming journey with Central Executive Transfers'.($ref ? ' — '.$ref : ''),
            $this->isReviewRequest() => 'How was your journey with Central Executive Transfers?',
            default => 'A message from Central Executive Transfers'.($ref ? ' — '.$ref : ''),
        };
    }
}
