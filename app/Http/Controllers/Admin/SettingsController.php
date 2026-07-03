<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * In-app settings so keys can be pasted without touching .env or the terminal.
 * Currently the Google Maps key (address autocomplete + distance pricing).
 */
class SettingsController extends Controller
{
    public function index(): View
    {
        return view('admin.settings.index', [
            'mapsKey' => Setting::get('google_maps_key'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'google_maps_key' => ['nullable', 'string', 'max:120'],
        ]);

        Setting::set('google_maps_key', trim((string) $data['google_maps_key']), 'string', 'integrations');

        return back()->with('status', 'Settings saved.');
    }
}
