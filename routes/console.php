<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Deliver due WhatsApp reminders (24h / 2h before pickup).
Schedule::command('cet:send-due-messages')->everyMinute()->withoutOverlapping();

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

// Calendar kill-switch: skip ALL calendar jobs while paused (default: paused)
// or when the hard env override CALENDAR_SYNC_ENABLED=false is set.
$calendarPaused = fn () => config('services.google_calendar.sync_enabled') === false
    || \App\Models\Setting::get('calendar_paused', true);

// Push pending booking events to Google Calendar, every 5 minutes.
Schedule::command('cet:sync-calendar')->everyFiveMinutes()->withoutOverlapping()->skip($calendarPaused);

// Parse Outlook booking emails into bookings, every 5 minutes. (Captures emails
// into the database; the calendar push within is itself paused by the switch.)
Schedule::command('cet:ingest-outlook')->everyFiveMinutes()->withoutOverlapping();

// Safety net: re-confirm every upcoming booking is on the calendar, hourly.
Schedule::command('cet:verify-calendar')->hourly()->withoutOverlapping()->skip($calendarPaused);
