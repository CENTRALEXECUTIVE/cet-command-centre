<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VehicleType;
use App\Services\BookingIntakeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "New booking from a message" — the operator pastes the booking text they were
 * sent, the AI formats it into the exact CET calendar format, and a preview is
 * shown. NOTHING is created and NOTHING touches the calendar until the operator
 * reviews the preview and presses Confirm.
 */
class BookingIntakeController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        return view('admin.intake.index', ['vehicleTypes' => $this->vehicleTypes()]);
    }

    /**
     * Build the preview. Accepts either raw pasted text (first pass → AI parse)
     * or already-extracted fields (operator edited them and hit "Update
     * preview"). Never saves anything.
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

        return view('admin.intake.preview', [
            'fields' => $fields,
            'preview' => $intake->preview($fields),
            'vehicleTypes' => $this->vehicleTypes(),
            'aiAvailable' => app(\App\Services\Ai\AnthropicService::class)->configured(),
        ]);
    }

    /** Persist the confirmed booking and build/push its calendar event. */
    public function confirm(Request $request, BookingIntakeService $intake): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'fields' => ['required', 'array'],
            'fields.lead_name' => ['required', 'string', 'max:190'],
            'fields.pickup_at' => ['required', 'string', 'max:40'],
            'fields.pickup_address' => ['required', 'string', 'max:255'],
            'fields.destination_address' => ['required', 'string', 'max:255'],
        ], [
            'fields.lead_name.required' => 'Add the passenger name before confirming.',
            'fields.pickup_at.required' => 'Add the pickup date & time before confirming.',
            'fields.pickup_address.required' => 'Add the pickup address before confirming.',
            'fields.destination_address.required' => 'Add the drop-off before confirming.',
        ]);

        $booking = $intake->confirm($data['fields'], $request->user());

        return redirect()->route('bookings.show', $booking)
            ->with('status', 'Booking created and added to the calendar. Review it below.');
    }

    /** @return \Illuminate\Support\Collection<int, VehicleType> */
    private function vehicleTypes()
    {
        return VehicleType::orderBy('sort_order')->pluck('name');
    }
}
