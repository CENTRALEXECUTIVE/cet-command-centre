<?php

namespace App\Http\Controllers;

use App\Models\CoverDriver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The drivers directory (admin): a roster of drivers — including third-party
 * cover drivers — that the office picks from when preparing a reminder. Simple
 * add / edit / remove; no logins or compliance, just the details that go on the
 * customer's "• Driver details" message.
 */
class CoverDriverController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        return view('cover-drivers.index', [
            'drivers' => CoverDriver::orderByDesc('is_active')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $this->validated($request);
        CoverDriver::create($data + ['is_active' => true]);

        return back()->with('status', "Driver {$data['name']} added.");
    }

    public function update(Request $request, CoverDriver $coverDriver): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $coverDriver->update($this->validated($request) + ['is_active' => $request->boolean('is_active')]);

        return back()->with('status', 'Driver updated.');
    }

    public function destroy(Request $request, CoverDriver $coverDriver): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $coverDriver->delete();

        return back()->with('status', 'Driver removed.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'vehicle_reg' => ['nullable', 'string', 'max:16'],
            'vehicle' => ['nullable', 'string', 'max:80'],
        ]);
    }
}
