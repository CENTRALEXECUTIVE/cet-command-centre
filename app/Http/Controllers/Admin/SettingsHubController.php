<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The one Settings hub — a single landing page that gathers every office control
 * (pricing, fleet, booking channels, integrations, people, data) into labelled
 * cards, so staff have an ETO-style "settings side" to run the business from
 * without hunting through the sidebar.
 */
class SettingsHubController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        return view('admin.settings.hub', [
            'isSuperAdmin' => (bool) $request->user()->isSuperAdmin(),
        ]);
    }
}
