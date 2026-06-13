<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Deliver due WhatsApp reminders (24h / 2h before pickup).
Schedule::command('cet:send-due-messages')->everyMinute()->withoutOverlapping();
