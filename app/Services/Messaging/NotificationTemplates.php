<?php

namespace App\Services\Messaging;

use App\Models\Booking;
use App\Models\Setting;

/**
 * Ready-made customer/driver notification templates, mirroring the set ETO
 * offered on the booking page (New confirmed booking, Unconfirmed booking, Quote
 * request, Cancelled, Incomplete, Driver assignment removed). These build the
 * message TEXT only — the office still sends it by hand (WhatsApp / SMS / email
 * deep links), so nothing auto-sends to a customer.
 *
 * Each template's wording is editable by the office (Settings → Message templates),
 * stored per key in `settings` and interpolated with the {tokens} below; an unset
 * template falls back to the built-in default here.
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

    /** The placeholders a template may use, with a one-line description for the editor. */
    public const PLACEHOLDERS = [
        '{ref}' => 'Booking reference',
        '{first}' => 'Customer first name',
        '{when}' => 'Pickup date & time',
        '{from}' => 'Pickup address',
        '{to}' => 'Drop-off address',
        '{fare}' => 'Fare (or “to be confirmed”)',
        '{sign}' => 'Company sign-off',
    ];

    /** Built-in default wording per template (tokens filled at send time). */
    public const DEFAULTS = [
        'new_confirmed' => "*Booking confirmed* — {ref}\n\nHi {first}, your journey is confirmed:\n📅 {when}\n📍 {from}\n🏁 {to}\n💷 {fare}\n\nWe'll be in touch with your driver's details before pickup.\n\n{sign}",
        'unconfirmed' => "*Booking received* — {ref}\n\nHi {first}, thank you — we've received your booking request for:\n📅 {when}\n📍 {from}\n🏁 {to}\n\nOur office will confirm it shortly.\n\n{sign}",
        'quote_request' => "*Your quote* — {ref}\n\nHi {first}, thank you for your enquiry:\n📅 {when}\n📍 {from}\n🏁 {to}\n💷 Quoted: {fare}\n\nReply to confirm and we'll book it in.\n\n{sign}",
        'cancelled' => "*Booking cancelled* — {ref}\n\nHi {first}, your booking for {when} ({from} → {to}) has been cancelled. If this wasn't expected, please contact our office.\n\n{sign}",
        'incomplete' => "*Action needed* — {ref}\n\nHi {first}, your booking for {when} isn't complete yet. Please complete your payment to secure it, or contact our office for help.\n\n{sign}",
        'driver_removed' => "*Assignment removed* — {ref}\n\nThe driver assignment for the {when} job ({from} → {to}) has been removed. Please disregard this job.\n\n{sign}",
    ];

    public static function label(string $template): string
    {
        return self::TEMPLATES[$template] ?? ucfirst(str_replace('_', ' ', $template));
    }

    /** The raw template text (office override, else the built-in default). */
    public static function rawTemplate(string $template): string
    {
        $override = Setting::get(self::settingKey($template));

        return filled($override) ? (string) $override : (self::DEFAULTS[$template] ?? '{ref}\n{when}\n{from} → {to}\n\n{sign}');
    }

    public static function settingKey(string $template): string
    {
        return 'msg_template.'.$template;
    }

    /** The message body for a template, filled from the booking. */
    public function body(Booking $booking, string $template): string
    {
        $ref = $booking->external_reference ?: $booking->reference;
        $name = $booking->displayName() ?: ($booking->customer?->name ?: 'there');
        $first = trim(explode(' ', (string) $name)[0]) ?: 'there';
        $fare = $booking->fareGross();

        $tokens = [
            '{ref}' => $ref,
            '{first}' => $first,
            '{when}' => $booking->pickup_at?->format('D d M Y, H:i') ?: 'to be confirmed',
            '{from}' => $booking->displayPickupAddress() ?: '—',
            '{to}' => $booking->displayDropoffAddress() ?: '—',
            '{fare}' => $fare !== null ? '£'.number_format($fare, 2) : 'to be confirmed',
            '{sign}' => '*Central Executive Transfers*',
        ];

        return strtr(self::rawTemplate($template), $tokens);
    }
}
