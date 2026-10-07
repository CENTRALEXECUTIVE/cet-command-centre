<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SystemHealth;
use App\Support\Heartbeat;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * System Health — one screen telling the office whether everything that keeps the
 * business running is actually running: the scheduler cron, calendar sync, backups,
 * and the outside connections (Square, Maps, Calendar, Twilio). Admin-only, and
 * built to never itself error.
 */
class HealthController extends Controller
{
    public function index(Request $request, SystemHealth $health): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        $checks = $health->checks();
        $groups = collect($checks)->groupBy('group');

        return view('admin.health.index', [
            'groups' => $groups,
            'worst' => $health->worst(),
            'emailFeed' => new \App\Support\EmailFeedStatus,
            'schedulerLast' => Heartbeat::last('scheduler'),
            'jobs' => [
                'auto-deploy' => Heartbeat::last('auto-deploy'),
                'sync-calendar' => Heartbeat::last('sync-calendar'),
                'status-watchdog' => Heartbeat::last('status-watchdog'),
                'backup-database' => Heartbeat::last('backup-database'),
                'send-due-messages' => Heartbeat::last('send-due-messages'),
            ],
        ]);
    }
}
