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
