<?php

namespace App\Services\Messaging;

use App\Models\Booking;

/**
 * Ready-made customer/driver notification templates, mirroring the set ETO
 * offered on the booking page (New confirmed booking, Unconfirmed booking, Quote
 * request, Cancelled, Incomplete, Driver assignment removed). These build the
 * message TEXT only — the office still sends it by hand (WhatsApp / SMS / email
 * deep links), so nothing auto-sends to a customer.
 */
class NotificationTemplates
{
    /** template key => human label (order shown in the dropdown). */
    public const TEMPLATES = [
        'new_confirmed' => 'New confirmed booking',
        'unconfirmed' => 'Unconfirmed booking',
        'quote_request' => 'Quote request',
        'cancelled' => 'Cancelled',
        'incomplete' => 'Incomplete',
        'driver_removed' => 'Driver assignment removed',
    ];

    /** Recipients this notification can be aimed at. */
    public const RECIPIENTS = [
        'customer' => 'Customer',
        'driver' => 'Driver',
    ];

    public static function label(string $template): string
    {
        return self::TEMPLATES[$template] ?? ucfirst(str_replace('_', ' ', $template));
    }

    /** The message body for a template, filled from the booking. */
    public function body(Booking $booking, string $template): string
    {
        $ref = $booking->external_reference ?: $booking->reference;
        $name = $booking->displayName() ?: ($booking->customer?->name ?: 'there');
        $first = trim(explode(' ', (string) $name)[0]) ?: 'there';
        $when = $booking->pickup_at?->format('D d M Y, H:i');
        $from = $booking->displayPickupAddress();
        $to = $booking->displayDropoffAddress();
        $fare = $booking->fareGross();
        $fareLine = $fare !== null ? '£'.number_format($fare, 2) : null;
        $sign = '*Central Executive Transfers*';

        return match ($template) {
            'new_confirmed' => "*Booking confirmed* — {$ref}\n\nHi {$first}, your journey is confirmed:\n"
                ."📅 {$when}\n📍 {$from}\n🏁 {$to}"
                .($fareLine ? "\n💷 {$fareLine}" : '')
                ."\n\nWe'll be in touch with your driver's details before pickup.\n\n{$sign}",

            'unconfirmed' => "*Booking received* — {$ref}\n\nHi {$first}, thank you — we've received your booking request for:\n"
                ."📅 {$when}\n📍 {$from}\n🏁 {$to}\n\n"
                ."Our office will confirm it shortly.\n\n{$sign}",

            'quote_request' => "*Your quote* — {$ref}\n\nHi {$first}, thank you for your enquiry:\n"
                ."📅 {$when}\n📍 {$from}\n🏁 {$to}"
                .($fareLine ? "\n💷 Quoted: {$fareLine}" : '')
                ."\n\nReply to confirm and we'll book it in.\n\n{$sign}",

            'cancelled' => "*Booking cancelled* — {$ref}\n\nHi {$first}, your booking for {$when} ({$from} → {$to}) has been cancelled. "
                ."If this wasn't expected, please contact our office.\n\n{$sign}",

            'incomplete' => "*Action needed* — {$ref}\n\nHi {$first}, your booking for {$when} isn't complete yet"
                .($fareLine ? " — {$fareLine} is outstanding" : '')
                .". Please complete your payment to secure it, or contact our office for help.\n\n{$sign}",

            'driver_removed' => "*Assignment removed* — {$ref}\n\nThe driver assignment for the {$when} job "
                ."({$from} → {$to}) has been removed. Please disregard this job.\n\n{$sign}",

            default => "{$ref}\n{$when}\n{$from} → {$to}\n\n{$sign}",
        };
    }
}
