<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Super-admin page controlling which watchdog alerts buzz each admin's phone:
 * a toggle per event type, a "critical only" master switch, and the optional
 * new-critical chime on the dashboard.
 */
class NotificationPreferencesController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        return view('admin.notifications.index', [
            'admins' => $this->admins(),
            'types' => User::ALERT_TYPES,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $data = $request->validate(['prefs' => ['array']]);

        foreach ($this->admins() as $admin) {
            $row = $data['prefs'][$admin->id] ?? [];
            $prefs = [];
            foreach (array_keys(User::ALERT_TYPES) as $type) {
                $prefs[$type] = (bool) ($row[$type] ?? false);
            }
            $prefs['critical_only'] = (bool) ($row['critical_only'] ?? false);
            $prefs['chime'] = (bool) ($row['chime'] ?? false);
            $prefs['alarm'] = (bool) ($row['alarm'] ?? false);

            $admin->forceFill(['notification_preferences' => $prefs])->save();
        }

        return back()->with('status', 'Notification preferences saved.');
    }

    /**
     * Fire a TEST at-risk alert so the office can confirm the safety net works:
     * a critical alert (dashboard siren + phone push to every admin) and a test
     * call to the office line.
     */
    public function test(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        // Critical event → open dashboards blare the siren + admins get a push.
        app(\App\Services\Watchdog\AdminAlerts::class)->notify(
            'at_risk',
            '🔔 TEST alert — this is only a test',
            'If you can see/hear this, critical alerts are reaching you. No action needed.',
            'critical',
        );
        \App\Models\WatchdogEvent::log('test_alert', '🔔 TEST alert — critical alerts are working', 'critical');

        $call = app(\App\Services\Telephony\OfficeAlertCall::class);
        $callMsg = $call->configured()
            ? ($call->ringTest()
                ? 'Test call placed to '.$call->target().' — it should ring now.'
                : 'Test call FAILED — check the Twilio credentials.')
            : 'Auto-call not set up yet (add Twilio + CET_ALERT_CALL_FROM + CET_OFFICE_CALL_NUMBER).';

        return back()->with('status', 'Test alert fired — the dashboard siren should sound and a push has been sent. '.$callMsg);
    }

    /**
     * Place ONLY a test phone call to the business line (no siren, no push) so the
     * operator can confirm the emergency line actually rings.
     */
    public function testCall(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $call = app(\App\Services\Telephony\OfficeAlertCall::class);
        if (! $call->configured()) {
            return back()->with('error', 'Auto-call isn’t set up yet — add the Twilio account (SID + token), a voice “from” number, and the office line. Run cet:alert-call-status to see what’s missing.');
        }

        $placed = $call->ringTest();

        return back()->with($placed ? 'status' : 'error',
            $placed
                ? 'Calling '.$call->target().' now — it should ring in a few seconds.'
                : 'Couldn’t place the call — check the Twilio credentials and the “from” number.');
    }

    private function admins()
    {
        return User::where('role', UserRole::Admin->value)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }
}
