<?php

namespace App\Http\Controllers;

use App\Models\CoverDriver;
use App\Services\DriverRosterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The drivers directory (admin): a roster of drivers — including third-party
 * cover drivers — that the office picks from when preparing a reminder. Each
 * entry is also kept in step with a real, assignable driver account so it can be
 * given jobs on the dispatch board.
 */
class CoverDriverController extends Controller
{
    public function __construct(private readonly DriverRosterService $roster) {}

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
        $data['phone'] = $this->roster->normalizePhone($data['phone'] ?? null);
        $driver = CoverDriver::create($data + ['is_active' => true]);
        $this->roster->ensureUser($driver);

        return back()->with('status', "Driver {$data['name']} added and available to assign.");
    }

    public function update(Request $request, CoverDriver $coverDriver): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $this->validated($request);
        $data['phone'] = $this->roster->normalizePhone($data['phone'] ?? null);
        $coverDriver->update($data + ['is_active' => $request->boolean('is_active')]);
        $this->roster->ensureUser($coverDriver);

        return back()->with('status', 'Driver updated.');
    }

    public function destroy(Request $request, CoverDriver $coverDriver): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        // Keep the driver account (it may be on past jobs) but stop it being
        // offered — just deactivate it, then drop the directory entry.
        if ($coverDriver->user_id) {
            \App\Models\User::where('id', $coverDriver->user_id)->update(['is_active' => false]);
        }
        $coverDriver->delete();

        return back()->with('status', 'Driver removed.');
    }

    /** Create/refresh assignable driver accounts for the whole roster. */
    public function sync(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $count = 0;
        foreach (CoverDriver::all() as $driver) {
            $driver->phone = $this->roster->normalizePhone($driver->phone);
            $driver->save();
            $this->roster->ensureUser($driver);
            $count++;
        }
        $this->roster->pruneOrphanSyntheticDrivers();

        return back()->with('status', "{$count} driver(s) synced — they're now assignable on the dispatch board.");
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
