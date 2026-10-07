{{-- Scheduler-down warning. Request-driven (it must work precisely WHEN the
     scheduler is dead, so it can't rely on the scheduler). Crash-safe: Heartbeat
     swallows any error and reports "not stale". --}}
@php $schedStale = \App\Support\Heartbeat::schedulerStale(); @endphp
@if($schedStale)
    <div class="alert alert-error" role="alert" style="display:flex;gap:10px;align-items:flex-start;border-left:4px solid #cf3b31">
        <span style="font-size:18px;line-height:1">⛔</span>
        <span>
            <strong>Background jobs have stopped.</strong>
            The scheduler cron hasn't run in over 5 minutes, so auto-deploy, calendar sync, reminders, the watchdog and backups are <strong>not running</strong>.
            Install/restart the cron on the server:
            <code>* * * * * cd ~/cet-staging &amp;&amp; php artisan schedule:run</code>.
            <a href="{{ route('health.index') }}">Open System Health →</a>
        </span>
    </div>
@endif

{{-- Warns the office when CET's calendar mirror has gone stale, so out-of-date
     booking money is never shown as if it were current. Crash-safe: CalendarHealth
     swallows any DB/settings error and reports "not stale". --}}
@php $calHealth = app(\App\Services\Calendar\CalendarHealth::class); @endphp
@if($calHealth->isStale())
    <div class="alert alert-error" role="alert" style="display:flex;gap:10px;align-items:flex-start;border-left:4px solid #c0392b">
        <span style="font-size:18px;line-height:1">⚠️</span>
        <span>
            <strong>Google Calendar mirror is behind.</strong>
            Last successful sync <strong>{{ $calHealth->ageForHumans() }} ago</strong>.
            The Command Centre is the source of truth, so bookings here are current — but anything still drawn from the calendar mirror (the <strong>payment line</strong>) may lag until it catches up. The office should confirm outbound internet / DNS on the server.
        </span>
    </div>
@endif
