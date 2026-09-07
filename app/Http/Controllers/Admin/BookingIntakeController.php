<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VehicleType;
use App\Services\BookingIntakeService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "New booking from a message" — the operator pastes the booking text they were
 * sent, the FREE deterministic parser (no AI cost) formats it into the exact
 * CET calendar block, and a copy-ready preview is shown.
 *
 * This tool NEVER creates a booking in the Command Centre. The operator adds
 * the block to Google Calendar and the 5-minute sync imports it once — so the
 * calendar stays the single source of truth and there are no duplicates.
 */
class BookingIntakeController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        return view('admin.intake.index', ['vehicleTypes' => $this->vehicleTypes()]);
    }

    /**
     * Build the copy-ready calendar preview. Accepts either raw pasted text
     * (first pass → free parse) or already-extracted fields (operator edited
     * them and hit "Update preview"). Never saves anything.
     */
    public function preview(Request $request, BookingIntakeService $intake): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        $fields = $request->input('fields');
        if (is_array($fields)) {
            $fields = $intake->normalise($fields);
        } else {
            $request->validate(['raw' => ['required', 'string', 'max:5000']]);
            $fields = $intake->parse($request->string('raw'));
        }

        // Who the rotation would give this job — bake the tag into the title so
        // the copied calendar block already reads the right person (ABDI/MAJ/…).
        $nextDriver = $intake->nextDriver($fields);
        if (empty($fields['driver_tag']) && $nextDriver) {
            $fields['driver_tag'] = $nextDriver['tag'];
        }

        return view('admin.intake.preview', [
            'fields' => $fields,
            'preview' => $intake->preview($fields),
            'vehicleTypes' => $this->vehicleTypes(),
            // The paste box is always available — parsing is free (no AI).
            'aiAvailable' => true,
            'nextDriver' => $nextDriver,
        ]);
    }

    /**
     * Add the previewed booking straight into the Command Centre (for covering /
     * non-ETO jobs the operator enters by hand), instead of only copying it onto
     * the calendar. Builds the calendar event too, then opens the new booking.
     */
    public function store(Request $request, BookingIntakeService $intake): \Illuminate\Http\RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $fields = $request->input('fields');
        abort_unless(is_array($fields), 422);

        $booking = $intake->create($fields, $request->user());

        return redirect()->route('bookings.show', $booking)
            ->with('status', 'Booking added to the Command Centre.');
    }

    /** @return \Illuminate\Support\Collection<int, VehicleType> */
    private function vehicleTypes()
    {
        return VehicleType::orderBy('sort_order')->pluck('name');
    }
}
