<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Scheduler heartbeat — stamps "the cron ran" every minute. If this stops, the
// whole background layer (deploy, calendar sync, reminders, watchdog, backups) is
// down; the System Health page and admin banner read it to surface that at once.
Schedule::command('cet:heartbeat')->everyMinute();

// Deliver due WhatsApp reminders (24h / 2h before pickup).
//
// NOTE on withoutOverlapping(MINUTES): the number is the LOCK EXPIRY. Without it
// Laravel defaults to 1440 (24 HOURS). If a run is ever killed mid-flight — and
// cet:auto-deploy below does `git reset --hard` every minute, which CAN interrupt
// an in-flight command — the lock is orphaned and EVERY future scheduled run of
// that command silently skips for up to 24h, while a MANUAL `php artisan …` run
// (which doesn't take the lock) still works. That is exactly the "manual ingest
// adds the booking but it never happens automatically" symptom. Giving each lock
// a short expiry (a little longer than the command's own runtime) means a stale
// lock self-clears within minutes instead of jamming the schedule for a day.
Schedule::command('cet:send-due-messages')->everyMinute()->withoutOverlapping(5)
    ->after(fn () => \App\Support\Heartbeat::stamp('send-due-messages'));

// Make sure every upcoming booking (incl. ETO imports) has a reminder prepared
// and on the "to send" list, and that any reminder queued later than the evening
// cutoff is pulled back to it. Every ten minutes so a freshly-imported ETO job
// surfaces on the worklist quickly — this used to be done on every dashboard
// load, which made the home page slow; the scheduler now owns it. Idempotent
// and cheap (idempotent ensure* skips anything already queued).
Schedule::command('cet:prepare-reminders')->everyTenMinutes()->withoutOverlapping(10);

// The Command Centre backs itself up: a full gzipped database snapshot every
// hour (keeps the newest 72 ≈ 3 days), so data can never be silently lost and
// any state can be recalled with cet:restore-database. Read-only against data.
Schedule::command('cet:backup-database')->hourly()->withoutOverlapping(30)
    ->after(fn () => \App\Support\Heartbeat::stamp('backup-database'));

// GDPR: prune GPS pings past the retention window, daily.
Schedule::command('cet:prune-gps')->dailyAt('03:00');

// Fleet & driver compliance: sync dates and send renewal reminders, daily.
Schedule::command('cet:check-compliance')->dailyAt('08:00');

// Monthly corporate VAT invoices (1st of the month, for the previous month).
Schedule::command('cet:generate-invoices')->monthlyOn(1, '06:00');

// Standing/recurring bookings — create upcoming occurrences a few days ahead.
Schedule::command('cet:generate-recurring')->dailyAt('04:30')->withoutOverlapping(30);

// Flight delay monitoring for upcoming airport pickups, every 15 minutes.
Schedule::command('cet:check-flights')->everyFifteenMinutes()->withoutOverlapping(15);

// Google Ads metrics sync (when API configured), daily.
Schedule::command('cet:sync-ads')->dailyAt('05:00');

// Push pending booking events to Google Calendar, every 5 minutes. This ADDS
// new bookings in the correct format (CalendarEventBuilder) and matches existing
// events by reference so it never duplicates. ICS import stays disabled (rule
// 10 — the corruption source). Operator-driven edits/deletions are NOT done here.
Schedule::command('cet:sync-calendar')->everyFiveMinutes()->withoutOverlapping(10)
    ->after(fn () => \App\Support\Heartbeat::stamp('sync-calendar'));

// PULL upcoming bookings into line with the live calendar (read-only), in the
// shell where the Google connection is reliable — so the website never has to
// reach Google and bookings stay matched to the calendar automatically.
Schedule::command('cet:calendar-refresh')->everyFiveMinutes()->withoutOverlapping(10);

// Allocate tagged jobs (ABDI/MAJ or any named driver in the calendar title) to
// their driver automatically — the safety net for the import-time assignment, so
// existing and freshly-pulled jobs are picked up without hand-allocating.
Schedule::command('cet:auto-allocate-tagged')->everyFiveMinutes()->withoutOverlapping(10);

// Parse Outlook booking emails into bookings, every 2 minutes — so an ETO
// amendment or cancellation reaches the Command Centre quickly.
Schedule::command('cet:ingest-outlook')->everyTwoMinutes()->withoutOverlapping(10);

// Turn Outlook customer enquiries into reviewable quotes + draft replies, every
// 10 minutes (during the sending window).
Schedule::command('cet:ingest-enquiries')->everyTenMinutes()->withoutOverlapping(10);

// Safety net: re-confirm every upcoming booking is on the calendar, hourly.
Schedule::command('cet:verify-calendar')->hourly()->withoutOverlapping(30);

// Set driver pay to the standard 90% of fare on every not-yet-done booking,
// automatically — no "Confirm pay" click. Every ten minutes so a freshly
// imported/pasted job (and any job that only gets a fare later) has its pay set
// hands-off. Never overwrites a pay already on the job; leaves discounted jobs
// for the office. Allocation already sets it instantly; this is the net.
Schedule::command('cet:apply-default-pay')->everyTenMinutes()->withoutOverlapping(10);

// Self-heal wrongly-linked bookings (a booking stapled to a different person's
// shared customer record) — re-file each under its own customer, hourly. Precise
// (name AND number differ, non-corporate) and capped, so it's safe to run alone.
Schedule::command('cet:check-customer-links --fix')->hourly()->withoutOverlapping(30);

// Status watchdog: nudge drivers who haven't set off / tapped the next status,
// detect arrivals/POB/complete from GPS, and feed the dashboard alerts log.
Schedule::command('cet:status-watchdog')->everyMinute()->withoutOverlapping(5)
    ->after(fn () => \App\Support\Heartbeat::stamp('status-watchdog'));

// Number masking safety net: close Proxy sessions past drop-off + 4h even if
// a status change was missed. Twilio's own expiry backs this up.
Schedule::command('cet:close-proxy-sessions')->everyMinute()->withoutOverlapping(5);

// Self-deploy: pull and apply the latest code from the deploy branch EVERY
// MINUTE, using this same scheduler cron — so a pushed fix goes live on its own,
// within the minute, with no separate cron to maintain. No-op when up to date.
// Turn off with CET_AUTO_DEPLOY=false in the environment.
Schedule::command('cet:auto-deploy')->everyMinute()->withoutOverlapping(5)
    ->after(fn () => \App\Support\Heartbeat::stamp('auto-deploy'));
