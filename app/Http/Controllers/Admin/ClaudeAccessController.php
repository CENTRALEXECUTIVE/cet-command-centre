<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\ClaudeReportController;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** Settings → Claude access: create or turn off the read-only report key (super admins). */
class ClaudeAccessController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        return view('admin.claude-access', [
            'enabled' => filled(Setting::get(ClaudeReportController::HASH_KEY)),
            'createdAt' => Setting::get('claude_report_token_created'),
            'newKey' => session('claude_new_key'),
        ]);
    }

    public function generate(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $key = 'cet_'.Str::random(44);
        Setting::set(ClaudeReportController::HASH_KEY, hash('sha256', $key), 'string', 'security');
        Setting::set('claude_report_token_created', now()->timezone(config('app.timezone'))->format('j M Y, H:i').' by '.$request->user()->name, 'string', 'security');

        // Shown once on the next page load, never stored in plain text.
        return redirect()->route('claude-access.index')->with('claude_new_key', $key);
    }

    public function revoke(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        Setting::set(ClaudeReportController::HASH_KEY, '', 'string', 'security');
        Setting::set('claude_report_token_created', '', 'string', 'security');

        return redirect()->route('claude-access.index')->with('status', 'Claude access turned off. The old key no longer works.');
    }
}
