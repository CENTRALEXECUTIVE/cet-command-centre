<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Airport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Manage airports — the list that drives the booking form's airport picker and the
 * driver rotation. Add a new airport, rename one, turn it on/off, or set which one
 * is the "Free Roam" general pool. Admin-only.
 */
class AirportController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        return view('admin.airports.index', [
            'airports' => Airport::orderByDesc('is_general_pool')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $this->validated($request);
        Airport::create($data);

        return back()->with('status', "{$data['name']} added.");
    }

    public function update(Request $request, Airport $airport): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $airport->update($this->validated($request, $airport));

        return back()->with('status', "{$airport->name} saved.");
    }

    public function destroy(Request $request, Airport $airport): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        // Never orphan bookings: block deletion while any reference this airport.
        if ($airport->bookings()->exists()) {
            return back()->with('error', "{$airport->name} can't be deleted — bookings are linked to it. Turn it off instead.");
        }

        $name = $airport->name;
        $airport->delete();

        return back()->with('status', "{$name} deleted.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Airport $airport = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:16', Rule::unique('airports', 'code')->ignore($airport?->id)],
            'name' => ['required', 'string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
            'is_general_pool' => ['nullable', 'boolean'],
        ]);

        $data['code'] = strtoupper(trim($data['code']));
        $data['is_active'] = $request->boolean('is_active');
        $data['is_general_pool'] = $request->boolean('is_general_pool');

        return $data;
    }
}
