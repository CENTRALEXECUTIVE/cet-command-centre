<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Deliver due WhatsApp reminders (24h / 2h before pickup).
Schedule::command('cet:send-due-messages')->everyMinute()->withoutOverlapping();

// Make sure every upcoming booking (incl. ETO imports) has a reminder prepared
// and on the "to send" list. Runs a few times a day within the sending window.
Schedule::command('cet:prepare-reminders')->twiceDaily(8, 14)->withoutOverlapping();

// GDPR: prune GPS pings past the retention window, daily.
Schedule::command('cet:prune-gps')->dailyAt('03:00');

// Fleet & driver compliance: sync dates and send renewal reminders, daily.
Schedule::command('cet:check-compliance')->dailyAt('08:00');

// Monthly corporate VAT invoices (1st of the month, for the previous month).
Schedule::command('cet:generate-invoices')->monthlyOn(1, '06:00');

// Flight delay monitoring for upcoming airport pickups, every 15 minutes.
Schedule::command('cet:check-flights')->everyFifteenMinutes()->withoutOverlapping();

// Google Ads metrics sync (when API configured), daily.
Schedule::command('cet:sync-ads')->dailyAt('05:00');

// Push pending booking events to Google Calendar, every 5 minutes. This ADDS
// new bookings in the correct format (CalendarEventBuilder) and matches existing
// events by reference so it never duplicates. ICS import stays disabled (rule
// 10 — the corruption source). Operator-driven edits/deletions are NOT done here.
Schedule::command('cet:sync-calendar')->everyFiveMinutes()->withoutOverlapping();

// PULL upcoming bookings into line with the live calendar (read-only), in the
// shell where the Google connection is reliable — so the website never has to
// reach Google and bookings stay matched to the calendar automatically.
Schedule::command('cet:calendar-refresh')->everyFiveMinutes()->withoutOverlapping();

// Parse Outlook booking emails into bookings, every 5 minutes.
Schedule::command('cet:ingest-outlook')->everyFiveMinutes()->withoutOverlapping();

// Turn Outlook customer enquiries into reviewable quotes + draft replies, every
// 10 minutes (during the sending window).
Schedule::command('cet:ingest-enquiries')->everyTenMinutes()->withoutOverlapping();

// Safety net: re-confirm every upcoming booking is on the calendar, hourly.
Schedule::command('cet:verify-calendar')->hourly()->withoutOverlapping();
