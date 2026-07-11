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

            $admin->forceFill(['notification_preferences' => $prefs])->save();
        }

        return back()->with('status', 'Notification preferences saved.');
    }

    private function admins()
    {
        return User::where('role', UserRole::Admin->value)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }
}
