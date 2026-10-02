<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A single "who did what, when" activity log across the whole system — created,
 * updated, deleted, exported, logins. Read-only, admin-only.
 */
class ActivityLogController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        $query = AuditLog::with('user')->latest('created_at');

        if ($action = $request->query('action')) {
            $query->where('action', $action);
        }
        if ($userId = $request->integer('user')) {
            $query->where('user_id', $userId);
        }

        return view('admin.activity-log.index', [
            'logs' => $query->paginate(60)->withQueryString(),
            'action' => $request->query('action'),
            'userId' => $request->integer('user'),
            'actions' => AuditLog::query()->select('action')->distinct()->orderBy('action')->pluck('action'),
            'users' => User::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
